<?php

namespace App\Http\Controllers\Api;

use App\Jobs\FanOutLiveStreamStartedNotifications;
use App\Jobs\OptimizeStoredMedia;
use App\Jobs\StartLiveEgressRecording;
use App\Jobs\StopLiveEgressRecording;
use App\Models\LiveStream;
use App\Models\LiveStreamViewer;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workout;
use App\Services\LiveRecordingAssembler;
use App\Services\MediaStorage;
use App\Services\LiveKitTokenService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LiveStreamController extends Controller
{
    public function __construct(private readonly LiveKitTokenService $tokens)
    {
    }

    public function active(Request $request)
    {
        $section = $request->validate(['section' => ['nullable', 'in:workouts,experts']])['section'] ?? 'workouts';
        // Browsers can delay background requests for tens of seconds, especially
        // on mobile devices. Do not terminate a healthy LiveKit session because
        // of a short heartbeat gap.
        $staleBefore = now()->subMinutes(3);
        $staleStreams = LiveStream::query()
            ->where('status', 'live')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('host_heartbeat_at')
                    ->orWhere('host_heartbeat_at', '<', $staleBefore);
            })
            ->get();
        foreach ($staleStreams as $staleStream) {
            $staleStream->update(['status' => 'ended', 'ended_at' => now(), 'host_heartbeat_at' => null]);
            $staleStream->viewers()->update(['status' => 'ended']);
            $this->stopAndFinalizeEgress($staleStream);
        }

        $stream = LiveStream::query()
            ->where('status', 'live')
            ->where('section', $section)
            ->with('host:id,name')
            ->latest('started_at')
            ->first();

        return response()->json(['data' => $stream]);
    }

    public function start(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'access_level' => ['nullable', 'in:free,paid'],
            'section' => ['nullable', 'in:workouts,experts'],
        ]);

        $previousStreams = LiveStream::query()
            ->where('host_id', $request->user()->id)
            ->where('status', 'live')
            ->get();
        foreach ($previousStreams as $previousStream) {
            $previousStream->update(['status' => 'ended', 'ended_at' => now(), 'host_heartbeat_at' => null]);
            $previousStream->viewers()->update(['status' => 'ended']);
            $this->stopAndFinalizeEgress($previousStream);
        }

        $stream = LiveStream::query()->create([
            'host_id' => $request->user()->id,
            'status' => 'live',
            'participants_enabled' => true,
            'recording_title' => trim($validated['title']),
            'recording_description' => trim($validated['description']),
            'recording_access_level' => $validated['access_level'] ?? 'paid',
            'started_at' => now(),
            'host_heartbeat_at' => now(),
            'section' => $validated['section'] ?? 'workouts',
            'egress_status' => 'EGRESS_QUEUED',
        ]);
        $stream->update(['room_name' => 'novaya-ya-live-'.$stream->id]);

        StartLiveEgressRecording::dispatch($stream->id)->afterCommit();
        FanOutLiveStreamStartedNotifications::dispatch($stream->id)->afterCommit();

        return response()->json(['data' => $stream], 201);
    }

    public function startRecording(Request $request, LiveStream $stream)
    {
        abort_unless(
            $request->user()->role->value === 'admin'
            && $stream->host_id === $request->user()->id
            && $stream->status === 'live',
            403,
        );

        if ($stream->egress_id || in_array($stream->egress_status, ['EGRESS_QUEUED', 'EGRESS_STARTING', 'EGRESS_RETRYING'], true)) {
            return response()->json(['data' => $stream]);
        }

        $stream->forceFill(['egress_status' => 'EGRESS_QUEUED'])->save();
        StartLiveEgressRecording::dispatch($stream->id)->afterCommit();

        return response()->json(['data' => $stream->fresh()], 202);
    }

    public function end(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);

        $stream->update(['status' => 'ended', 'ended_at' => now(), 'host_heartbeat_at' => null, 'guest_enabled' => false, 'participants_enabled' => false]);
        $stream->viewers()->update(['status' => 'ended']);

        $this->stopAndFinalizeEgress($stream);

        return response()->json(['data' => $stream->fresh()]);
    }

    public function heartbeat(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        // The page can be unloaded while a heartbeat is already in flight. A
        // heartbeat for an ended stream is safe to ignore and must not create a
        // spurious 409 error in the browser console during logout.
        if ($stream->status !== 'live')
            return response()->noContent();

        $stream->update(['host_heartbeat_at' => now()]);

        return response()->noContent();
    }

    public function token(Request $request, LiveStream $stream)
    {
        abort_if($stream->status !== 'live', 409, 'Эфир уже завершен.');

        $user = $request->user();
        $isHost = $user->role->value === 'admin' && $stream->host_id === $user->id;
        $isViewer = $user->role->value === 'curator'
            || ($user->role->value === 'client' && $user->access_status === 'paid');
        abort_unless(
            $isHost || $isViewer,
            403,
            'Эфир доступен только кураторам и пользователям с полным доступом',
        );

        if ($isViewer) {
            LiveStreamViewer::query()->updateOrCreate(
                ['live_stream_id' => $stream->id, 'user_id' => $user->id],
                ['status' => 'connected'],
            );
        }

        return response()->json([
            'data' => [
                // Participants may enable their own camera and microphone. The client only
                // renders the host's media on the main stage, so participant media cannot
                // replace the workout broadcast for other viewers.
                'token' => $this->tokens->createRoomToken($user, $stream, $isHost || ($isViewer && $stream->participants_enabled)),
                'url' => config('services.livekit.public_url'),
                'room' => $stream->room_name,
                'role' => $isHost ? 'host' : 'viewer',
            ],
        ]);
    }

    public function createGuestLink(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        abort_if($stream->status !== 'live', 409, 'Эфир уже завершён.');

        $stream->update([
            'guest_token' => $stream->guest_token ?: Str::random(48),
            'guest_enabled' => true,
            'guest_expires_at' => now()->addDay(),
        ]);

        return response()->json(['data' => [
            'path' => '/live/'.$stream->guest_token,
            'expires_at' => $stream->guest_expires_at,
            'enabled' => true,
        ]]);
    }

    public function disableGuestLink(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        $stream->update(['guest_enabled' => false]);

        return response()->noContent();
    }

    public function setConference(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        abort_if($stream->status !== 'live', 409, 'Эфир уже завершён.');
        $stream->update(['participants_enabled' => $request->boolean('enabled')]);

        return response()->json(['data' => $stream->fresh()]);
    }

    public function guestInfo(string $token)
    {
        $stream = $this->guestStream($token);

        return response()->json(['data' => [
            'host_name' => $stream->host->name,
            'started_at' => $stream->started_at,
        ]]);
    }

    public function guestToken(string $token)
    {
        $stream = $this->guestStream($token);

        return response()->json(['data' => [
            'token' => $this->tokens->createGuestRoomToken($stream, Str::uuid()->toString(), $stream->participants_enabled),
            'url' => config('services.livekit.public_url'),
            'room' => $stream->room_name,
            'host_identity' => (string) $stream->host_id,
            'can_publish' => $stream->participants_enabled,
        ]]);
    }

    /**
     * Return all guest join data from a single live-stream snapshot. Previously
     * the browser requested info and token in parallel; an end/disable action
     * between those two requests made a valid link look randomly invalid.
     */
    public function guestJoin(string $token)
    {
        $stream = $this->guestStream($token);

        return response()->json(['data' => [
            'host_name' => $stream->host->name,
            'host_identity' => (string) $stream->host_id,
            'token' => $this->tokens->createGuestRoomToken($stream, Str::uuid()->toString(), $stream->participants_enabled),
            'url' => config('services.livekit.public_url'),
            'room' => $stream->room_name,
            'can_publish' => $stream->participants_enabled,
        ]]);
    }

    private function guestStream(string $token): LiveStream
    {
        return LiveStream::query()
            ->where('guest_token', $token)
            ->where('guest_enabled', true)
            ->where('status', 'live')
            ->where(fn ($query) => $query->whereNull('guest_expires_at')->orWhere('guest_expires_at', '>', now()))
            ->with('host:id,name')
            ->firstOrFail();
    }

    private function stopAndFinalizeEgress(LiveStream $stream): void
    {
        StopLiveEgressRecording::dispatch($stream->id)->afterCommit();
    }

    public function viewers(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);

        return response()->json([
            'data' => $stream->viewers()->with('user:id,name')->latest()->get(),
        ]);
    }

    public function storeRecording(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        if ($stream->recording_workout_id) {
            return response()->json(['data' => $stream->recordingWorkout]);
        }
        $validated = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/webm,video/mp4,video/x-m4v', 'max:512000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $videoPath = app(MediaStorage::class)->storeOptimized($request->file('video'), 'workouts/videos', 'video');

        $workout = Workout::query()->create([
            'title' => $stream->recording_title ?: 'Запись эфира от '.$stream->started_at->format('d.m.Y H:i'),
            'description' => $stream->recording_description ?: 'Запись прямой трансляции с тренером.',
            'video_path' => $videoPath,
            'duration_seconds' => $validated['duration_seconds'] ?? max(0, $stream->started_at->diffInSeconds($stream->ended_at ?? now())),
            'timer_seconds' => 45,
            'access_level' => $stream->recording_access_level ?: 'paid',
            'section' => $stream->section,
        ]);
        $stream->update([
            'recording_workout_id' => $workout->id,
            'status' => 'ended',
            'ended_at' => $stream->ended_at ?? now(),
        ]);
        $stream->viewers()->update(['status' => 'ended']);

        User::query()
            ->where('role', 'client')
            ->where('access_status', 'paid')
            ->each(fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'workout',
                'title' => 'Доступна запись эфира',
                'body' => 'Запись завершенного эфира добавлена в раздел '.($stream->section === 'experts' ? '«Эфиры с экспертами».' : '«Тренировки».'),
                'data' => ['workout_id' => $workout->id, 'link_url' => $stream->section === 'experts' ? '/expert-lives' : '/workouts'],
            ]));

        return response()->json(['data' => $workout], 201);
    }

    public function storeRecordingSegment(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        abort_if($stream->recording_workout_id, 409, 'Запись этого эфира уже опубликована.');

        $validated = $request->validate([
            'segment' => ['required', 'file', 'mimetypes:video/webm,video/mp4,video/x-m4v', 'max:131072'],
            'sequence' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);
        $file = $request->file('segment');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'webm');
        $extension = in_array($extension, ['webm', 'mp4', 'm4v'], true) ? $extension : 'webm';
        $path = sprintf(
            'live-recordings/%d/segments/%06d.%s',
            $stream->id,
            $validated['sequence'],
            $extension,
        );

        Storage::disk('s3')->put($path, fopen($file->getRealPath(), 'rb'), [
            'visibility' => 'private',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            'CacheControl' => 'private, no-store',
        ]);

        return response()->json([
            'data' => [
                'sequence' => $validated['sequence'],
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    public function finalizeRecording(
        Request $request,
        LiveStream $stream,
        LiveRecordingAssembler $assembler,
    ) {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);
        if ($stream->recording_workout_id) {
            return response()->json(['data' => $stream->recordingWorkout]);
        }

        $validated = $request->validate([
            'segment_count' => ['required', 'integer', 'min:1', 'max:600'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);
        $videoPath = $assembler->assemble($stream->id, $validated['segment_count']);

        $workout = Workout::query()->create([
            'title' => $stream->recording_title ?: 'Запись эфира от '.$stream->started_at->format('d.m.Y H:i'),
            'description' => $stream->recording_description ?: 'Запись прямой трансляции с тренером.',
            'video_path' => $videoPath,
            'duration_seconds' => $validated['duration_seconds'] ?? max(0, $stream->started_at->diffInSeconds($stream->ended_at ?? now())),
            'timer_seconds' => 45,
            'access_level' => $stream->recording_access_level ?: 'paid',
            'section' => $stream->section,
        ]);
        OptimizeStoredMedia::dispatch(Workout::class, $workout->id, 'video_path', $videoPath, 'video');

        $stream->update([
            'recording_workout_id' => $workout->id,
            'status' => 'ended',
            'ended_at' => $stream->ended_at ?? now(),
        ]);
        $stream->viewers()->update(['status' => 'ended']);

        User::query()
            ->where('role', 'client')
            ->where('access_status', 'paid')
            ->each(fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'workout',
                'title' => 'Доступна запись эфира',
                'body' => 'Запись завершённого эфира добавлена в раздел '.($stream->section === 'experts' ? '«Эфиры с экспертами».' : '«Тренировки».'),
                'data' => ['workout_id' => $workout->id, 'link_url' => $stream->section === 'experts' ? '/expert-lives' : '/workouts'],
            ]));

        return response()->json(['data' => $workout], 201);
    }
}

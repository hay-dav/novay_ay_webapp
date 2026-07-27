<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveStream;
use App\Models\LiveStreamViewer;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workout;
use App\Services\MediaStorage;
use App\Services\LiveKitTokenService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LiveStreamController extends Controller
{
    public function __construct(private readonly LiveKitTokenService $tokens)
    {
    }

    public function active()
    {
        // Browsers can delay background requests for tens of seconds, especially
        // on mobile devices. Do not terminate a healthy LiveKit session because
        // of a short heartbeat gap.
        $staleBefore = now()->subMinutes(3);
        LiveStream::query()
            ->where('status', 'live')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('host_heartbeat_at')
                    ->orWhere('host_heartbeat_at', '<', $staleBefore);
            })
            ->update(['status' => 'ended', 'ended_at' => now(), 'host_heartbeat_at' => null]);

        $stream = LiveStream::query()
            ->where('status', 'live')
            ->with('host:id,name')
            ->latest('started_at')
            ->first();

        return response()->json(['data' => $stream]);
    }

    public function start(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);

        LiveStream::query()
            ->where('host_id', $request->user()->id)
            ->where('status', 'live')
            ->update(['status' => 'ended', 'ended_at' => now()]);

        $stream = LiveStream::query()->create([
            'host_id' => $request->user()->id,
            'status' => 'live',
            'started_at' => now(),
            'host_heartbeat_at' => now(),
        ]);
        $stream->update(['room_name' => 'novaya-ya-live-'.$stream->id]);

        User::query()
            ->where('role', 'client')
            ->where('access_status', 'paid')
            ->each(fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'live_stream',
                'title' => 'Прямой эфир начался',
                'body' => 'Анастасия начала прямую трансляцию. Подключайтесь в разделе «Тренировки».',
                'data' => ['live_stream_id' => $stream->id, 'link_url' => '/workouts'],
            ]));

        return response()->json(['data' => $stream], 201);
    }

    public function end(Request $request, LiveStream $stream)
    {
        abort_unless($request->user()->role->value === 'admin' && $stream->host_id === $request->user()->id, 403);

        $stream->update(['status' => 'ended', 'ended_at' => now(), 'host_heartbeat_at' => null]);
        $stream->viewers()->update(['status' => 'ended']);

        return response()->json(['data' => $stream]);
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
        $isViewer = $user->role->value === 'client' && $user->access_status === 'paid';
        abort_unless(
            $isHost || $isViewer,
            403,
            'Эфир доступен только платным пользователям',
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
                'token' => $this->tokens->createRoomToken($user, $stream, $isHost || $isViewer),
                'url' => config('services.livekit.public_url'),
                'room' => $stream->room_name,
                'role' => $isHost ? 'host' : 'viewer',
            ],
        ]);
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

        $videoPath = app(MediaStorage::class)->store($request->file('video'), 'workouts/videos');

        $workout = Workout::query()->create([
            'title' => 'Запись эфира от '.$stream->started_at->format('d.m.Y H:i'),
            'description' => 'Запись прямой трансляции с тренером.',
            'video_path' => $videoPath,
            'duration_seconds' => $validated['duration_seconds'] ?? max(0, $stream->started_at->diffInSeconds($stream->ended_at ?? now())),
            'timer_seconds' => 45,
            'access_level' => 'paid',
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
                'body' => 'Запись завершенного эфира добавлена в раздел «Тренировки».',
                'data' => ['workout_id' => $workout->id],
            ]));

        return response()->json(['data' => $workout], 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Models\LiveStream;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutCompletion;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class WorkoutController extends Controller
{
    public function index(Request $request)
    {
        $section = $request->validate(['section' => ['nullable', 'in:workouts,experts']])['section'] ?? 'workouts';
        $user = $request->user();
        $isPaid = $request->user()->access_status === 'paid'
            || in_array($request->user()->role->value, ['admin', 'curator', 'trainer'], true);
        $canDownloadLiveRecordings = in_array($request->user()->role->value, ['admin', 'curator'], true);

        $workouts = Workout::query()
            ->where('section', $section)
            ->when(! $isPaid, fn ($query) => $query->where('access_level', 'free'))
            ->latest()
            ->get();
        $recordingWorkoutIds = LiveStream::query()
            ->where('section', $section)
            ->whereNotNull('recording_workout_id')
            ->pluck('recording_workout_id')
            ->flip();
        $completedWorkoutIds = $user->role->value === 'client'
            ? WorkoutCompletion::query()->where('user_id', $user->id)->pluck('workout_id')->flip()
            : collect();

        $media = app(MediaStorage::class);
        $workouts->each(function (Workout $workout) use ($request, $recordingWorkoutIds, $completedWorkoutIds, $canDownloadLiveRecordings, $media): void {
            $workout->setAttribute('cover_path', $media->publicUrl($workout->cover_path));
            $workout->setAttribute('content_type', $recordingWorkoutIds->has($workout->id) ? 'live' : 'video');
            $workout->setAttribute('is_completed', $completedWorkoutIds->has($workout->id));
            if ($workout->video_path) {
                $workout->setAttribute('video_path', URL::temporarySignedRoute(
                    'workouts.stream',
                    now()->addHours(6),
                    ['workout' => $workout->id, 'user' => $request->user()->id],
                ));
            }
            if ($workout->mobile_video_path) {
                $workout->setAttribute('mobile_video_path', URL::temporarySignedRoute(
                    'workouts.stream',
                    now()->addHours(6),
                    ['workout' => $workout->id, 'user' => $request->user()->id, 'variant' => 'mobile'],
                ));
            }
            if ($canDownloadLiveRecordings && $recordingWorkoutIds->has($workout->id)) {
                $workout->setAttribute('download_url', URL::temporarySignedRoute(
                    'workouts.download',
                    now()->addHours(1),
                    ['workout' => $workout->id, 'user' => $request->user()->id],
                ));
            }
        });

        return response()->json(['data' => $workouts]);
    }

    public function summary(Request $request)
    {
        return response()->json(['data' => [
            'completed_workouts_count' => WorkoutCompletion::query()
                ->where('user_id', $request->user()->id)
                ->count(),
        ]]);
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'cover' => ['nullable', 'image', 'max:10240'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-m4v', 'max:2097152'],
            'access_level' => ['required', 'in:free,paid'],
            'section' => ['nullable', 'in:workouts,experts'],
        ]);
        $section = $validated['section'] ?? 'workouts';
        abort_if($section === 'experts' && ! in_array($request->user()->role->value, ['admin', 'curator'], true), 403);

        $media = app(MediaStorage::class);
        $coverPath = $request->file('cover')
            ? $media->storeOptimized($request->file('cover'), 'workouts/covers', 'image', true)
            : null;
        $videoPath = $media->storeOptimized($request->file('video'), 'workouts/videos', 'video');

        $workout = Workout::query()->create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'cover_path' => $coverPath,
            'video_path' => $videoPath,
            'duration_seconds' => 0,
            'timer_seconds' => 45,
            'access_level' => $validated['access_level'],
            'section' => $section,
        ]);
        User::query()
            ->where('role', 'client')
            ->when($workout->access_level === 'paid', fn ($query) => $query->where('access_status', 'paid'))
            ->each(fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'workout',
                'title' => $section === 'experts' ? 'Добавлен новый эфир' : 'Добавлена новая тренировка',
                'body' => ($section === 'experts' ? 'Эфир' : 'Тренировка').' «'.$workout->title.'» уже доступен в вашем кабинете.',
                'data' => ['workout_id' => $workout->id, 'link_url' => $section === 'experts' ? '/expert-lives' : '/workouts'],
            ]));

        return response()->json(['data' => $workout], 201);
    }

    public function destroy(Request $request, Workout $workout)
    {
        abort_unless($request->user()->role->value === 'admin', 403);

        $media = app(MediaStorage::class);
        $media->delete($workout->cover_path);
        $media->delete($workout->video_path);
        $media->delete($workout->mobile_video_path);
        $workout->delete();

        return response()->noContent();
    }

    public function update(Request $request, Workout $workout)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'access_level' => ['required', 'in:free,paid'],
        ]);

        $workout->update($validated);

        return response()->json(['data' => $workout->fresh()]);
    }

    public function complete(Request $request, Workout $workout)
    {
        $completion = WorkoutCompletion::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'workout_id' => $workout->id],
            ['completed_at' => now()],
        );

        return response()->json(['data' => $completion]);
    }

    public function stream(Request $request, Workout $workout)
    {
        $user = User::query()->findOrFail($request->integer('user'));
        if ($user->role->value === 'client') {
            abort_if($workout->access_level === 'paid' && $user->access_status !== 'paid', 403);
        }

        $videoPath = $request->query('variant') === 'mobile' && $workout->mobile_video_path
            ? $workout->mobile_video_path
            : $workout->video_path;

        if (Str::startsWith((string) $videoPath, ['http://', 'https://'])) {
            return redirect()->away($videoPath);
        }

        return redirect()->away(app(MediaStorage::class)->secureCdnUrl($videoPath, 3600));
    }

    public function download(Request $request, Workout $workout)
    {
        $user = User::query()->findOrFail($request->integer('user'));
        abort_unless(in_array($user->role->value, ['admin', 'curator'], true), 403);
        abort_unless(LiveStream::query()->where('recording_workout_id', $workout->id)->exists(), 404);

        if (Str::startsWith((string) $workout->video_path, ['http://', 'https://'])) {
            return redirect()->away($workout->video_path);
        }

        $filename = (Str::slug($workout->title) ?: 'live-recording').'.mp4';
        $downloadUrl = app(MediaStorage::class)->secureCdnDownloadUrl($workout->video_path, $filename);

        return redirect()->away($downloadUrl);
    }
}

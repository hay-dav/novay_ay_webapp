<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workout;
use App\Services\LiveEgressRecordingProcessor;
use App\Services\LiveKitEgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinalizeLiveEgressRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;
    public int $timeout = 300;

    public function __construct(public readonly int $streamId)
    {
        $this->onQueue('media');
    }

    public function backoff(): int
    {
        return 10;
    }

    public function handle(LiveKitEgressService $egress, LiveEgressRecordingProcessor $processor): void
    {
        $stream = LiveStream::query()->find($this->streamId);
        if (! $stream || $stream->recording_workout_id || ! $stream->egress_id || ! $stream->egress_path) {
            return;
        }

        $details = $egress->find($stream->egress_id);
        if (! $details) {
            $this->release(10);
            return;
        }

        $status = strtoupper((string) ($details['status'] ?? ''));
        $stream->update(['egress_status' => $status]);
        if (in_array($status, ['EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING', '0', '1', '2'], true)) {
            $this->release(10);
            return;
        }
        if (! in_array($status, ['EGRESS_COMPLETE', '3'], true)) {
            Log::error('LiveKit Egress failed', ['stream_id' => $stream->id, 'egress' => $details]);
            return;
        }
        if (! Storage::disk('s3')->exists($stream->egress_path)) {
            $this->release(10);
            return;
        }

        $videoPath = $processor->process($stream->egress_path);
        if (! Storage::disk('s3')->exists($videoPath)) {
            throw new \RuntimeException('The processed live recording was not confirmed in S3.');
        }

        $workout = DB::transaction(function () use ($stream, $videoPath): ?Workout {
            $lockedStream = LiveStream::query()->lockForUpdate()->find($stream->id);
            if (! $lockedStream || $lockedStream->recording_workout_id) {
                return null;
            }

            $workout = Workout::query()->create([
                'title' => $lockedStream->recording_title ?: 'Запись эфира от '.$lockedStream->started_at->format('d.m.Y H:i'),
                'description' => $lockedStream->recording_description ?: 'Запись прямой трансляции с тренером.',
                'video_path' => $videoPath,
                'mobile_video_path' => null,
                'duration_seconds' => max(0, $lockedStream->started_at->diffInSeconds($lockedStream->ended_at ?? now())),
                'timer_seconds' => 45,
                'access_level' => $lockedStream->recording_access_level ?: 'paid',
            ]);
            $lockedStream->forceFill([
                'recording_workout_id' => $workout->id,
                'egress_status' => 'published',
            ])->saveOrFail();

            return $workout;
        });
        if (! $workout) {
            Storage::disk('s3')->delete($videoPath);
            return;
        }

        $stream->refresh();
        if ($stream->recording_workout_id !== $workout->id || ! Workout::query()->whereKey($workout->id)->exists()) {
            throw new \RuntimeException('The live recording workout link was not persisted.');
        }

        Storage::disk('s3')->delete($stream->egress_path);

        User::query()->where('role', 'client')->where('access_status', 'paid')->each(
            fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'workout',
                'title' => 'Доступна запись эфира',
                'body' => 'Запись завершённого эфира добавлена в раздел «Тренировки».',
                'data' => ['workout_id' => $workout->id],
            ]),
        );
    }
}

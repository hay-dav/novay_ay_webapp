<?php

namespace App\Jobs;

use App\Models\Workout;
use App\Services\LiveEgressRecordingProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RegenerateLiveWorkoutVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public readonly int $workoutId)
    {
        $this->onQueue('media');
    }

    public function handle(LiveEgressRecordingProcessor $processor): void
    {
        $workout = Workout::query()->find($this->workoutId);
        if (! $workout || ! $workout->video_path || $workout->mobile_video_path) {
            return;
        }

        $sourcePath = $workout->video_path;
        if (! Storage::disk('s3')->exists($sourcePath)) {
            return;
        }

        $videoPath = $processor->process($sourcePath);
        $updated = DB::transaction(function () use ($sourcePath, $videoPath): bool {
            $lockedWorkout = Workout::query()->lockForUpdate()->find($this->workoutId);
            if (! $lockedWorkout
                || $lockedWorkout->video_path !== $sourcePath
                || $lockedWorkout->mobile_video_path) {
                return false;
            }

            $lockedWorkout->forceFill([
                'video_path' => $videoPath,
                'mobile_video_path' => null,
            ])->saveOrFail();

            return true;
        });

        if (! $updated) {
            Storage::disk('s3')->delete($videoPath);
            return;
        }

        Storage::disk('s3')->delete($sourcePath);
    }
}

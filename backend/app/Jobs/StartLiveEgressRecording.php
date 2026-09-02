<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Services\LiveKitEgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class StartLiveEgressRecording implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 45;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $streamId)
    {
        $this->onQueue('live');
    }

    public function uniqueId(): string
    {
        return (string) $this->streamId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [2, 5, 10, 20];
    }

    public function handle(LiveKitEgressService $egress): void
    {
        $stream = LiveStream::query()->find($this->streamId);
        if (! $stream || $stream->status !== 'live' || $stream->egress_id) {
            return;
        }

        $stream->forceFill(['egress_status' => 'EGRESS_STARTING'])->save();

        try {
            // Room Composite records the rendered room, not the host's local
            // MediaStream. Its template starts with a black stage, so recording
            // begins even when the host keeps camera and microphone disabled.
            $egress->createRoom($stream->room_name);
            $recording = $egress->startRoomComposite($stream);

            $stream->forceFill([
                'egress_id' => $recording['id'],
                'egress_path' => $recording['path'],
                'egress_status' => 'EGRESS_STARTING',
            ])->save();

            if ($stream->fresh()->status !== 'live') {
                StopLiveEgressRecording::dispatch($stream->id);
            }
        } catch (Throwable $exception) {
            $stream->forceFill(['egress_status' => 'EGRESS_RETRYING'])->save();
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        LiveStream::query()
            ->whereKey($this->streamId)
            ->whereNull('egress_id')
            ->update(['egress_status' => 'EGRESS_FAILED']);

        Log::error('Server-side live recording could not be started.', [
            'stream_id' => $this->streamId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

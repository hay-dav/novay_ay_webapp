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

class StopLiveEgressRecording implements ShouldBeUnique, ShouldQueue
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
        if (! $stream?->egress_id) {
            return;
        }

        $egress->stop($stream->egress_id);
        FinalizeLiveEgressRecording::dispatch($stream->id)->delay(now()->addSeconds(5));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Server-side live recording could not be stopped.', [
            'stream_id' => $this->streamId,
            'error' => $exception?->getMessage(),
        ]);

        // Finalizer can still discover an Egress that completed by itself.
        FinalizeLiveEgressRecording::dispatch($this->streamId)->delay(now()->addSeconds(10));
    }
}

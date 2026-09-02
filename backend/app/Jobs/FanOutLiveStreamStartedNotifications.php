<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FanOutLiveStreamStartedNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $streamId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->streamId;
    }

    public function handle(): void
    {
        if (! LiveStream::query()->whereKey($this->streamId)->where('status', 'live')->exists()) {
            return;
        }

        User::query()
            ->where('role', 'client')
            ->where('access_status', 'paid')
            ->select('id')
            ->chunkById(100, function ($users): void {
                SendLiveStreamStartedNotificationBatch::dispatch(
                    $this->streamId,
                    $users->pluck('id')->map(fn ($id) => (int) $id)->all(),
                );
            });
    }
}

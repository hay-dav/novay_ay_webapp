<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLiveStreamStartedNotificationBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    /** @param array<int, int> $userIds */
    public function __construct(
        public readonly int $streamId,
        public readonly array $userIds,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->streamId.':'.implode(',', $this->userIds);
    }

    public function handle(): void
    {
        $stream = LiveStream::query()
            ->whereKey($this->streamId)
            ->where('status', 'live')
            ->first();
        if (! $stream) {
            return;
        }

        $link = $stream->section === 'experts' ? '/expert-lives' : '/workouts';
        $sectionName = $stream->section === 'experts' ? '«Эфиры с экспертами».' : '«Тренировки».';

        foreach ($this->userIds as $userId) {
            Notification::query()->firstOrCreate(
                ['deduplication_key' => "live-started:{$stream->id}:{$userId}"],
                [
                    'user_id' => $userId,
                    'type' => 'live_stream',
                    'title' => 'Прямой эфир начался',
                    'body' => 'Началась прямая трансляция. Подключайтесь в разделе '.$sectionName,
                    'data' => ['live_stream_id' => $stream->id, 'link_url' => $link],
                ],
            );
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class SendWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification || ! $this->isConfigured()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ], [
            'TTL' => (int) config('webpush.ttl', 86400),
            'urgency' => 'normal',
            'batchSize' => 100,
            'contentType' => 'application/json',
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        $subscriptionsByEndpoint = [];
        foreach ($subscriptions as $storedSubscription) {
            $subscriptionsByEndpoint[$storedSubscription->endpoint] = $storedSubscription;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $storedSubscription->endpoint,
                    'keys' => [
                        'p256dh' => $storedSubscription->public_key,
                        'auth' => $storedSubscription->auth_token,
                    ],
                    'contentEncoding' => $storedSubscription->content_encoding,
                ]),
                $this->payload($notification),
            );
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            $storedSubscription = $subscriptionsByEndpoint[$endpoint] ?? null;
            if (! $storedSubscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $storedSubscription->forceFill(['last_used_at' => now()])->save();
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $storedSubscription->delete();
                continue;
            }

            Log::warning('Web Push delivery failed.', [
                'notification_id' => $notification->id,
                'subscription_id' => $storedSubscription->id,
                'reason' => $report->getReason(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Web Push job failed.', [
            'notification_id' => $this->notificationId,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function isConfigured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    private function payload(Notification $notification): string
    {
        $url = $notification->data['link_url'] ?? match ($notification->type) {
            'chat' => '/chat',
            'workout', 'live_stream' => '/workouts',
            'lesson' => '/lessons',
            default => '/app',
        };

        return (string) json_encode([
            'title' => mb_substr($notification->title, 0, 120),
            'body' => mb_substr($notification->body, 0, 700),
            'icon' => '/public-image/favicon.png?v=2',
            'badge' => '/public-image/favicon.png?v=2',
            'tag' => 'novaya-ya-'.$notification->id,
            'url' => $url,
            'notification_id' => $notification->id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

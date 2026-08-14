<?php

namespace App\Models;

use App\Events\NotificationCreated;
use App\Jobs\SendWebPushNotification;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected static function booted(): void
    {
        static::created(function (Notification $notification): void {
            $chatKey = $notification->data['chat_notification_key'] ?? null;
            $chatPushDisabled = $notification->type === 'chat'
                && $chatKey
                && ChatNotificationPreference::query()
                    ->where('user_id', $notification->user_id)
                    ->where('chat_key', $chatKey)
                    ->where('enabled', false)
                    ->exists();

            if (! $chatPushDisabled) {
                SendWebPushNotification::dispatch($notification->id)->afterCommit();
            }
            NotificationCreated::dispatch($notification);
        });
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}

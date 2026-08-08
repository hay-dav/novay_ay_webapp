<?php

namespace App\Models;

use App\Jobs\SendWebPushNotification;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected static function booted(): void
    {
        static::created(function (Notification $notification): void {
            SendWebPushNotification::dispatch($notification->id)->afterCommit();
        });
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatNotificationPreference extends Model
{
    protected $fillable = ['user_id', 'chat_key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}

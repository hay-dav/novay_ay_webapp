<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoomRead extends Model
{
    protected $fillable = ['chat_room_id', 'user_id', 'last_read_message_id'];
}

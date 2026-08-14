<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['chat_room_id', 'reply_to_id', 'sender_id', 'recipient_id', 'body', 'attachment_path', 'attachment_type', 'read_at', 'edited_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function room()
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function reactions()
    {
        return $this->hasMany(ChatReaction::class);
    }
}

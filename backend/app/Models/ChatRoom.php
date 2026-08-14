<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoom extends Model
{
    protected $fillable = ['slug', 'name'];

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}

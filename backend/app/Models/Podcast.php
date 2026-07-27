<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Podcast extends Model
{
    protected $fillable = ['author_id', 'title', 'description', 'cover_path', 'audio_path', 'access_level'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

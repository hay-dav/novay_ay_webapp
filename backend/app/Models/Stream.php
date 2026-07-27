<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stream extends Model
{
    protected $fillable = [
        'trainer_id',
        'course_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'stream_url',
        'recording_path',
        'status',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}

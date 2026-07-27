<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        'module_id',
        'title',
        'description',
        'type',
        'video_path',
        'audio_path',
        'duration_seconds',
        'is_preview',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_preview' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function module()
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }
}

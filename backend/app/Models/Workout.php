<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    public const DEFAULT_COVER_PATH = '/public-image/video-workout-cover-v1.png';

    protected $fillable = ['title', 'description', 'cover_path', 'video_path', 'mobile_video_path', 'duration_seconds', 'timer_seconds', 'access_level', 'section', 'sort_order'];

    public static function defaultCoverUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').self::DEFAULT_COVER_PATH;
    }

    protected static function booted(): void
    {
        static::creating(function (Workout $workout): void {
            if (($workout->section ?? 'workouts') === 'workouts') {
                $workout->cover_path = static::defaultCoverUrl();
            }
        });
    }
}

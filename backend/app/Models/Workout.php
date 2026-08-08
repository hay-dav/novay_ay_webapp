<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    protected $fillable = ['title', 'description', 'cover_path', 'video_path', 'mobile_video_path', 'duration_seconds', 'timer_seconds', 'access_level'];
}

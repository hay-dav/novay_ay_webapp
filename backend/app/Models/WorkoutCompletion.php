<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutCompletion extends Model
{
    protected $fillable = ['user_id', 'workout_id', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}


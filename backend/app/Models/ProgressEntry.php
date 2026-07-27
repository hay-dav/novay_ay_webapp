<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressEntry extends Model
{
    protected $fillable = ['user_id', 'weight_kg', 'waist_cm', 'hips_cm', 'chest_cm', 'photo_path', 'mood', 'comment', 'measured_on'];

    protected function casts(): array
    {
        return ['measured_on' => 'date'];
    }
}


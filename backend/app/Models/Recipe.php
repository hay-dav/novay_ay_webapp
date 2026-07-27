<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = [
        'title',
        'category',
        'photo_path',
        'ingredients',
        'steps',
        'calories',
        'protein_g',
        'fat_g',
        'carbs_g',
        'is_free',
    ];

    protected function casts(): array
    {
        return ['is_free' => 'boolean'];
    }
}


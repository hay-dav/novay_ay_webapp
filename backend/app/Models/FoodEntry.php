<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodEntry extends Model
{
    protected $fillable = [
        'user_id',
        'recipe_id',
        'meal_type',
        'title',
        'calories',
        'protein_g',
        'fat_g',
        'carbs_g',
        'eaten_on',
        'is_favorite',
    ];

    protected function casts(): array
    {
        return ['eaten_on' => 'date', 'is_favorite' => 'boolean'];
    }
}


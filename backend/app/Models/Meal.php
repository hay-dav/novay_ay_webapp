<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meal extends Model
{
    protected $fillable = ['nutrition_plan_id', 'meal_type', 'title', 'calories', 'protein_g', 'fat_g', 'carbs_g', 'eaten_at'];

    protected function casts(): array
    {
        return ['eaten_at' => 'datetime'];
    }
}


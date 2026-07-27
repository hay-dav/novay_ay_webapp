<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NutritionPlan extends Model
{
    protected $fillable = ['client_id', 'trainer_id', 'title', 'starts_on', 'ends_on', 'notes'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function meals()
    {
        return $this->hasMany(Meal::class);
    }
}


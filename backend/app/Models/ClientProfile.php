<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProfile extends Model
{
    protected $fillable = [
        'user_id',
        'trainer_id',
        'goal',
        'height_cm',
        'birth_date',
        'activity_level',
        'medical_notes',
        'calorie_goal',
        'protein_goal_g',
        'fat_goal_g',
        'carbs_goal_g',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }
}

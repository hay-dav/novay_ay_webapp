<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainerProfile extends Model
{
    protected $fillable = ['user_id', 'bio', 'specialization', 'experience_years', 'instagram_url'];
}


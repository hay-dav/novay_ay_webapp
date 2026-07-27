<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'trainer_id',
        'title',
        'slug',
        'description',
        'cover_path',
        'price_cents',
        'currency',
        'level',
        'status',
        'starts_at',
        'access_level',
        'sequential_access',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'sequential_access' => 'boolean'];
    }

    public function modules()
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }
}

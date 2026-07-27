<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonQuestion extends Model
{
    protected $fillable = ['lesson_id', 'user_id', 'answered_by', 'question', 'answer', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

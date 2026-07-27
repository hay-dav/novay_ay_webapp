<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleLesson extends Model
{
    protected $fillable = [
        'author_id',
        'title',
        'excerpt',
        'body',
        'image_path',
        'access_level',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function blocks()
    {
        return $this->hasMany(ArticleLessonBlock::class)->orderBy('sort_order');
    }
}

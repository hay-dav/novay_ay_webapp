<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleLessonBlock extends Model
{
    protected $fillable = ['article_lesson_id', 'type', 'content', 'image_path', 'sort_order'];

    public function lesson()
    {
        return $this->belongsTo(ArticleLesson::class, 'article_lesson_id');
    }
}

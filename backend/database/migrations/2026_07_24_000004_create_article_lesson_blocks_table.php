<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_lesson_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_lesson_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->text('content')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['article_lesson_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_lesson_blocks');
    }
};

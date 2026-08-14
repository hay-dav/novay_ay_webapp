<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_lessons', function (Blueprint $table): void {
            $table->integer('sort_order')->default(0)->index();
        });

        DB::table('article_lessons')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->each(function (int $lessonId, int $sortOrder): void {
                DB::table('article_lessons')->where('id', $lessonId)->update(['sort_order' => $sortOrder]);
            });
    }

    public function down(): void
    {
        Schema::table('article_lessons', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};

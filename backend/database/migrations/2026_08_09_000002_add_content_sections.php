<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_lessons', function (Blueprint $table): void {
            $table->string('section', 32)->default('lessons')->index();
        });
        Schema::table('workouts', function (Blueprint $table): void {
            $table->string('section', 32)->default('workouts')->index();
        });
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->string('section', 32)->default('workouts')->index();
        });
    }

    public function down(): void
    {
        Schema::table('live_streams', fn (Blueprint $table) => $table->dropColumn('section'));
        Schema::table('workouts', fn (Blueprint $table) => $table->dropColumn('section'));
        Schema::table('article_lessons', fn (Blueprint $table) => $table->dropColumn('section'));
    }
};

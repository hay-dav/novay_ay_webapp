<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('access_status')->default('free')->index();
            $table->string('group_name')->nullable()->index();
            $table->json('tags')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('client_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('calorie_goal')->default(1450);
            $table->unsignedInteger('protein_goal_g')->default(100);
            $table->unsignedInteger('fat_goal_g')->default(50);
            $table->unsignedInteger('carbs_goal_g')->default(150);
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->string('access_level')->default('paid')->index();
            $table->boolean('sequential_access')->default(true);
        });

        Schema::create('access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('questionnaire');
            $table->string('photo_path')->nullable();
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category')->index();
            $table->string('photo_path')->nullable();
            $table->text('ingredients');
            $table->text('steps');
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->boolean('is_free')->default(true);
            $table->timestamps();
        });

        Schema::create('favorite_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'recipe_id']);
        });

        Schema::create('food_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('meal_type')->default('meal');
            $table->string('title');
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->date('eaten_on');
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'eaten_on']);
        });

        Schema::create('workouts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_path')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedSmallInteger('timer_seconds')->default(45);
            $table->string('access_level')->default('paid')->index();
            $table->timestamps();
        });

        Schema::create('workout_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['user_id', 'workout_id']);
        });

        Schema::create('lesson_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['sender_id', 'recipient_id', 'created_at']);
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('link_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('lesson_questions');
        Schema::dropIfExists('workout_completions');
        Schema::dropIfExists('workouts');
        Schema::dropIfExists('food_entries');
        Schema::dropIfExists('favorite_recipes');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('access_requests');

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['access_level', 'sequential_access']);
        });

        Schema::table('client_profiles', function (Blueprint $table): void {
            $table->dropColumn(['calorie_goal', 'protein_goal_g', 'fat_goal_g', 'carbs_goal_g']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['access_status', 'group_name', 'tags', 'access_ends_at', 'blocked_at', 'archived_at']);
        });
    }
};


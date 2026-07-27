<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('meals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nutrition_plan_id')->constrained()->cascadeOnDelete();
            $table->string('meal_type');
            $table->string('title');
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->timestamp('eaten_at')->nullable();
            $table->timestamps();
        });

        Schema::create('progress_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_kg', 5, 2);
            $table->decimal('waist_cm', 5, 2)->nullable();
            $table->decimal('hips_cm', 5, 2)->nullable();
            $table->decimal('chest_cm', 5, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('mood')->nullable();
            $table->text('comment')->nullable();
            $table->date('measured_on');
            $table->timestamps();
            $table->index(['user_id', 'measured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_entries');
        Schema::dropIfExists('meals');
        Schema::dropIfExists('nutrition_plans');
    }
};


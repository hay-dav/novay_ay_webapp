<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_streams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('live')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('live_stream_viewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->jsonb('offer')->nullable();
            $table->jsonb('answer')->nullable();
            $table->timestamps();
            $table->unique(['live_stream_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_viewers');
        Schema::dropIfExists('live_streams');
    }
};

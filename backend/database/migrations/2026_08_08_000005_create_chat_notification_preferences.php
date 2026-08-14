<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat_key', 80);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'chat_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_notification_preferences');
    }
};

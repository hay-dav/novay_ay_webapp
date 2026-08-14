<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('chat_room_id')->nullable()->after('id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->change();
            $table->index(['chat_room_id', 'created_at']);
        });

        Schema::create('chat_room_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamps();
            $table->unique(['chat_room_id', 'user_id']);
        });

        DB::table('chat_rooms')->insert([
            'slug' => 'general',
            'name' => 'Общий чат',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_room_reads');
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropIndex(['chat_room_id', 'created_at']);
            $table->dropConstrainedForeignId('chat_room_id');
            $table->foreignId('recipient_id')->nullable(false)->change();
        });
        Schema::dropIfExists('chat_rooms');
    }
};

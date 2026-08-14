<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_id')->nullable()->after('chat_room_id')->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('read_at');
        });
        Schema::create('chat_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id', 'emoji']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('chat_reactions');
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('edited_at');
        });
    }
};

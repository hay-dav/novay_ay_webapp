<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->string('guest_token', 64)->nullable()->unique()->after('room_name');
            $table->boolean('guest_enabled')->default(false)->after('guest_token');
            $table->timestamp('guest_expires_at')->nullable()->after('guest_enabled');
            $table->boolean('participants_enabled')->default(false)->after('guest_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->dropUnique(['guest_token']);
            $table->dropColumn(['guest_token', 'guest_enabled', 'guest_expires_at', 'participants_enabled']);
        });
    }
};

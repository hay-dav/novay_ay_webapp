<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->timestamp('host_heartbeat_at')->nullable()->index()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->dropColumn('host_heartbeat_at');
        });
    }
};

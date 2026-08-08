<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->string('egress_id')->nullable()->unique()->after('room_name');
            $table->string('egress_path')->nullable()->after('egress_id');
            $table->string('egress_status')->nullable()->after('egress_path');
        });
    }

    public function down(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->dropUnique(['egress_id']);
            $table->dropColumn(['egress_id', 'egress_path', 'egress_status']);
        });
    }
};

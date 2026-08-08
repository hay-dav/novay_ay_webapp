<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->string('recording_title')->nullable()->after('recording_workout_id');
            $table->text('recording_description')->nullable()->after('recording_title');
            $table->string('recording_access_level')->nullable()->after('recording_description');
        });
    }

    public function down(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->dropColumn(['recording_title', 'recording_description', 'recording_access_level']);
        });
    }
};

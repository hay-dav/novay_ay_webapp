<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->string('mobile_video_path')->nullable()->after('video_path');
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->dropColumn('mobile_video_path');
        });
    }
};

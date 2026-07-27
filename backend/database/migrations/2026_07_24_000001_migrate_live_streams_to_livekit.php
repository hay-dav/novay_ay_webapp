<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_streams', function (Blueprint $table): void {
            $table->string('room_name')->nullable()->unique()->after('host_id');
        });

        DB::table('live_streams')
            ->orderBy('id')
            ->eachById(function (object $stream): void {
                DB::table('live_streams')
                    ->where('id', $stream->id)
                    ->update(['room_name' => 'novaya-ya-live-'.$stream->id]);
            });

        Schema::table('live_stream_viewers', function (Blueprint $table): void {
            $table->dropColumn(['offer', 'answer']);
        });
    }

    public function down(): void
    {
        Schema::table('live_stream_viewers', function (Blueprint $table): void {
            $table->jsonb('offer')->nullable();
            $table->jsonb('answer')->nullable();
        });

        Schema::table('live_streams', function (Blueprint $table): void {
            $table->dropUnique(['room_name']);
            $table->dropColumn('room_name');
        });
    }
};

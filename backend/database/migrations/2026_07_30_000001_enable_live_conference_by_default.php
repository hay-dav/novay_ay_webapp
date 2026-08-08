<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('live_streams')
            ->where('status', 'live')
            ->update(['participants_enabled' => true]);

        DB::statement('ALTER TABLE live_streams ALTER COLUMN participants_enabled SET DEFAULT TRUE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE live_streams ALTER COLUMN participants_enabled SET DEFAULT FALSE');
    }
};

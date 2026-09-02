<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_rooms')->where('slug', 'important-info')->update(['name' => 'ИНФО', 'updated_at' => now()]);
        DB::table('chat_rooms')->where('slug', 'general')->update(['name' => 'Чат ОБЩЕНИЕ', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('chat_rooms')->where('slug', 'important-info')->update(['name' => 'Важная ИНФА', 'updated_at' => now()]);
        DB::table('chat_rooms')->where('slug', 'general')->update(['name' => 'Общий чат', 'updated_at' => now()]);
    }
};

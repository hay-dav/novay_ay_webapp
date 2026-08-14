<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_rooms')->updateOrInsert(
            ['slug' => 'important-info'],
            ['name' => 'Важная ИНФА', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('chat_rooms')->where('slug', 'important-info')->delete();
    }
};

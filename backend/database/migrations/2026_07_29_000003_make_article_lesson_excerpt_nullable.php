<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE article_lessons ALTER COLUMN excerpt DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE article_lessons SET excerpt = '' WHERE excerpt IS NULL");
        DB::statement('ALTER TABLE article_lessons ALTER COLUMN excerpt SET NOT NULL');
    }
};

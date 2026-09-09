<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->index();
        });

        DB::table('workouts')
            ->where('section', 'experts')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->values()
            ->each(fn ($id, $sortOrder) => DB::table('workouts')->where('id', $id)->update(['sort_order' => $sortOrder]));
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};

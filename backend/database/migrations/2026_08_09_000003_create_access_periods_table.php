<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('months');
            $table->string('status')->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'starts_on']);
            $table->index(['status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_periods');
    }
};

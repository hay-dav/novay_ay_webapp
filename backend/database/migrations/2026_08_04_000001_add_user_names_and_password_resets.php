<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        DB::table('users')->orderBy('id')->eachById(function (object $user): void {
            [$firstName, $lastName] = $this->splitName((string) $user->name);
            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email_hash')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) {
            return [$parts[0] ?? 'Пользователь', null];
        }

        $surnamePattern = '/(ова|ёва|ева|ина|ына|ская|цкая|енко|чук|щук|юк|ук|ян|ко|ая)$/ui';
        if (count($parts) >= 3 && preg_match($surnamePattern, $parts[2])) {
            return [$parts[0], $parts[2]];
        }
        if (preg_match($surnamePattern, $parts[1])) {
            return [$parts[0], $parts[1]];
        }

        return [$parts[1], $parts[0]];
    }
};

<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS email_hash CHAR(64)');
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_hash CHAR(64)');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE TEXT');
        DB::statement('ALTER TABLE users ALTER COLUMN phone TYPE TEXT');

        DB::table('users')->select(['id', 'email', 'phone'])->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'email' => Crypt::encryptString((string) $user->email),
                'email_hash' => User::lookupHash((string) $user->email),
                'phone' => $user->phone ? Crypt::encryptString((string) $user->phone) : null,
                'phone_hash' => User::lookupHash($user->phone),
            ]);
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_hash_unique ON users (email_hash)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_phone_hash_unique ON users (phone_hash) WHERE phone_hash IS NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email_hash SET NOT NULL');
    }

    public function down(): void
    {
        throw new \RuntimeException('Encrypted personal data migration is intentionally irreversible.');
    }
};

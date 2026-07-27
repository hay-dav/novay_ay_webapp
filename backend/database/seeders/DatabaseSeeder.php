<?php

namespace Database\Seeders;

use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $adminEmail = mb_strtolower(trim((string) config('staff.admin.email')));
        $curatorEmail = mb_strtolower(trim((string) config('staff.curator.email')));
        $adminPassword = (string) config('staff.admin.initial_password');
        $curatorPassword = (string) config('staff.curator.initial_password');

        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
            || ! filter_var($curatorEmail, FILTER_VALIDATE_EMAIL)
            || hash_equals($adminEmail, $curatorEmail)
        ) {
            throw new RuntimeException('ADMIN_EMAIL and CURATOR_EMAIL must be valid and different.');
        }

        foreach ([$adminPassword, $curatorPassword] as $password) {
            if (mb_strlen($password) < 16) {
                throw new RuntimeException('Initial staff passwords must contain at least 16 characters.');
            }
        }

        foreach ([
            ['Анастасия Лазарева', $adminEmail, $adminPassword, 'admin'],
            ['Дина', $curatorEmail, $curatorPassword, 'curator'],
        ] as [$name, $email, $password, $role]) {
            $user = User::query()->updateOrCreate(
                ['email_hash' => User::lookupHash($email)],
                [
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => $role,
                    'access_status' => 'paid',
                    'email_verified_at' => now(),
                ],
            );

            TrainerProfile::query()->firstOrCreate(['user_id' => $user->id]);
        }
    }
}

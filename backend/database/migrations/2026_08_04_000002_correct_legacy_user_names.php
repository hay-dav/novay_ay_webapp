<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->orderBy('id')->eachById(function (object $user): void {
            $parts = preg_split('/\s+/u', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) {
                DB::table('users')->where('id', $user->id)->update([
                    'first_name' => $parts[0] ?? 'Пользователь',
                    'last_name' => null,
                ]);
                return;
            }

            $givenNames = [
                'Александра', 'Алина', 'Анастасия', 'Анна', 'Галина', 'Дарья', 'Дина',
                'Екатерина', 'Елена', 'Зинаида', 'Ирина', 'Ираида', 'Ксения', 'Лиана',
                'Людмила', 'Марина', 'Мария', 'Надежда', 'Наталия', 'Наталья', 'Оксана',
                'Ольга', 'Светлана', 'Сергей', 'Татьяна', 'Юлия', 'Янина', 'Давид',
            ];
            $firstIsGiven = in_array($parts[0], $givenNames, true);
            $secondIsGiven = in_array($parts[1], $givenNames, true);

            if ($firstIsGiven) {
                $lastName = count($parts) >= 3 && preg_match('/(овна|евна|ична|инична)$/ui', $parts[1])
                    ? $parts[2]
                    : $parts[1];
                $firstName = $parts[0];
            }
            elseif ($secondIsGiven) {
                $firstName = $parts[1];
                $lastName = $parts[0];
            }
            else {
                $firstName = $parts[1];
                $lastName = $parts[0];
            }

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        });
    }

    public function down(): void
    {
    }
};

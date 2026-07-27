<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', fn ($user, int $userId): bool => (int) $user->id === $userId);

Broadcast::channel('trainers.{trainerId}', function ($user, int $trainerId): bool {
    return (int) $user->id === $trainerId && in_array($user->role->value, ['curator', 'trainer', 'admin'], true);
});

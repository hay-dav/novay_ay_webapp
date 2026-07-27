<?php

namespace App\Services;

use App\Models\LiveStream;
use App\Models\User;
use RuntimeException;

class LiveKitTokenService
{
    public function createRoomToken(User $user, LiveStream $stream, bool $canPublish): string
    {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('LiveKit API credentials are not configured.');
        }

        $now = now()->timestamp;
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $apiKey,
            'sub' => (string) $user->id,
            'name' => $user->name,
            'nbf' => $now - 10,
            'exp' => $now + 60 * 60 * 4,
            'video' => [
                'roomJoin' => true,
                'room' => $stream->room_name,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => false,
            ],
            'metadata' => json_encode([
                'user_id' => $user->id,
                'role' => $user->role->value,
                'live_stream_id' => $stream->id,
            ], JSON_THROW_ON_ERROR),
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $apiSecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

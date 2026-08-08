<?php

namespace App\Services;

use App\Models\LiveStream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class LiveKitEgressService
{
    public function createRoom(string $roomName): void
    {
        $response = $this->request('livekit.RoomService', 'CreateRoom', [
            'name' => $roomName,
            // Keep the room available long enough for the host browser to
            // reconnect after a short mobile background interruption.
            'empty_timeout' => 300,
            'departure_timeout' => 20,
        ], ['roomCreate' => true]);

        if (! $response->successful()) {
            throw new RuntimeException('LiveKit could not create the room: '.$response->body());
        }
    }

    /** @return array{id: string, path: string} */
    public function startRoomComposite(LiveStream $stream): array
    {
        $path = 'live-recordings/server/stream-'.$stream->id.'-'.Str::uuid().'.mp4';
        $disk = config('filesystems.disks.s3');
        $endpoint = (string) ($disk['endpoint'] ?? '');

        if (blank($disk['key'] ?? null) || blank($disk['secret'] ?? null) || blank($disk['bucket'] ?? null) || $endpoint === '') {
            throw new RuntimeException('S3 settings for LiveKit Egress are incomplete.');
        }

        $response = $this->request('livekit.Egress', 'StartRoomCompositeEgress', [
            'room_name' => $stream->room_name,
            // A fixed grid must be used for recordings. The speaker layout
            // promotes whoever is currently talking and produces distracting
            // cuts in the saved live video.
            'layout' => 'grid',
            'custom_base_url' => 'http://frontend/egress.html?host='.urlencode((string) $stream->host_id),
            'advanced' => [
                // Deliberately limited for the current 4-vCPU server while
                // retaining a true vertical 720p recording.
                'width' => 720,
                'height' => 1280,
                'framerate' => 15,
                'video_bitrate' => 2_000,
                'audio_bitrate' => 96,
            ],
            'file_outputs' => [[
                'file_type' => 'MP4',
                'filepath' => $path,
                'disable_manifest' => true,
                's3' => [
                    'access_key' => $disk['key'],
                    'secret' => $disk['secret'],
                    'region' => $disk['region'] ?? 'ru-1',
                    'endpoint' => $endpoint,
                    'bucket' => $disk['bucket'],
                    'force_path_style' => filter_var($disk['use_path_style_endpoint'] ?? true, FILTER_VALIDATE_BOOL),
                ],
            ]],
        ], ['roomRecord' => true]);

        if (! $response->successful()) {
            throw new RuntimeException('LiveKit Egress could not start: '.$response->body());
        }

        $data = $response->json();
        $id = (string) ($data['egress_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('LiveKit Egress did not return an egress ID.');
        }

        return ['id' => $id, 'path' => $path];
    }

    public function stop(string $egressId): void
    {
        $response = $this->request('livekit.Egress', 'StopEgress', ['egress_id' => $egressId], ['roomRecord' => true]);

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('LiveKit Egress could not stop: '.$response->body());
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $egressId): ?array
    {
        // An empty PHP array is encoded as JSON `[]`, while LiveKit expects a
        // ListEgressRequest object. Filtering by ID both produces valid JSON
        // and avoids loading the complete Egress history.
        $response = $this->request('livekit.Egress', 'ListEgress', [
            'egress_id' => $egressId,
        ], ['roomRecord' => true]);
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit Egress status check failed: '.$response->body());
        }

        foreach ($response->json('items', []) as $item) {
            if (($item['egress_id'] ?? null) === $egressId) {
                return $item;
            }
        }

        return null;
    }

    private function request(string $service, string $method, array $payload, array $videoGrant)
    {
        $url = rtrim((string) config('services.livekit.internal_url'), '/').'/twirp/'.$service.'/'.$method;

        return Http::acceptJson()
            ->asJson()
            ->withToken($this->apiToken($videoGrant))
            ->timeout(20)
            ->post($url, $payload);
    }

    private function apiToken(array $videoGrant): string
    {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');
        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('LiveKit API credentials are not configured.');
        }

        $now = now()->timestamp;
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode([
                'iss' => $apiKey,
                'sub' => 'laravel-live-egress',
                'nbf' => $now - 10,
                'exp' => $now + 300,
                'video' => $videoGrant,
            ], JSON_THROW_ON_ERROR)),
        ];
        $segments[] = $this->base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $apiSecret, true));

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

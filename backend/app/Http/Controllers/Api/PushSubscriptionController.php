<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PushSubscriptionController extends Controller
{
    public function publicKey()
    {
        return response()->json([
            'enabled' => filled(config('webpush.vapid.public_key'))
                && filled(config('webpush.vapid.private_key')),
            'public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => [
                'required',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $scheme = parse_url((string) $value, PHP_URL_SCHEME);
                    $host = parse_url((string) $value, PHP_URL_HOST);
                    if ($scheme !== 'https' || ! is_string($host) || $host === '') {
                        $fail('Push endpoint must use HTTPS.');

                        return;
                    }

                    if ($host === 'localhost'
                        || str_ends_with($host, '.local')
                        || (filter_var($host, FILTER_VALIDATE_IP)
                            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                        $fail('Invalid push endpoint.');
                    }
                },
            ],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $endpointHash = hash('sha256', $validated['endpoint']);
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'last_used_at' => now(),
            ],
        );

        if ($subscription->wasRecentlyCreated) {
            Notification::query()->create([
                'user_id' => $request->user()->id,
                'type' => 'system',
                'title' => 'Уведомления включены',
                'body' => 'Теперь «Новая Я» сможет присылать уведомления, даже когда приложение закрыто.',
                'data' => ['link_url' => '/app'],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'active' => true,
            ],
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    public function store(UploadedFile $file, string $directory, bool $public = false): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        Storage::disk('s3')->put($path, $file->getContent(), [
            'visibility' => $public ? 'public' : 'private',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            'CacheControl' => $public ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && ! Str::startsWith($path, ['http://', 'https://'])) {
            Storage::disk('s3')->delete($path);
        }
    }

    public function publicUrl(?string $path): ?string
    {
        if (! $path || Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $base = rtrim((string) config('filesystems.cdn_url'), '/');

        return $base !== '' ? $base.'/'.ltrim($path, '/') : Storage::disk('s3')->url($path);
    }

    public function secureCdnUrl(string $path, int $ttlSeconds = 3600, ?string $ip = null): string
    {
        $base = rtrim((string) config('filesystems.cdn_url'), '/');
        $secret = (string) config('filesystems.cdn_secure_token');
        if ($base === '' || $secret === '') {
            return Storage::disk('s3')->temporaryUrl($path, now()->addSeconds($ttlSeconds));
        }

        $resourcePath = '/'.ltrim($path, '/');
        $expires = time() + $ttlSeconds;
        $signature = rtrim(strtr(base64_encode(md5($secret.$resourcePath.($ip ?? '').$expires, true)), '+/', '-_'), '=');

        return $base.'/md5('.$signature.','.$expires.')'.$resourcePath;
    }
}

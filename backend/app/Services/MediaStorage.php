<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaStorage
{
    public function store(UploadedFile $file, string $directory, bool $public = false): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        $this->putFile($path, $file->getRealPath(), $file->getMimeType() ?: 'application/octet-stream', $public);

        return $path;
    }

    public function storeLocalFile(
        string $localPath,
        string $directory,
        string $extension,
        string $contentType,
        bool $public = false,
    ): string {
        $path = trim($directory, '/').'/'.Str::uuid().'.'.ltrim(strtolower($extension), '.');
        $this->putFile($path, $localPath, $contentType, $public);

        return $path;
    }

    private function putFile(string $path, string $localPath, string $contentType, bool $public): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not open the local media file for upload.');
        }

        $stored = Storage::disk('s3')->put($path, $stream, [
            'visibility' => $public ? 'public' : 'private',
            'ContentType' => $contentType,
            'CacheControl' => $public ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
        fclose($stream);

        if (! $stored || ! Storage::disk('s3')->exists($path)) {
            throw new RuntimeException('S3 did not confirm the uploaded media file.');
        }
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

        // The Timeweb origin is a private bucket. Even assets that may be
        // displayed to authenticated users must therefore receive a signed CDN
        // URL; an unsigned CDN URL would be rejected by the private origin.
        return $this->secureCdnUrl($path, 86_400);
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

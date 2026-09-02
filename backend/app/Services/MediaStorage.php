<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaStorage
{
    /**
     * URLs created during the same interval must be identical so an edge cache
     * can share the object between viewers. The URL never lives longer than
     * the caller requested: it expires at the requested TTL from the start of
     * its current five-minute signing interval.
     */
    private const CDN_SIGNING_WINDOW_SECONDS = 172800;

    private const PRIVATE_MEDIA_CACHE_CONTROL = 'public, max-age=172800, s-maxage=172800, immutable';

    public function store(UploadedFile $file, string $directory, bool $public = false): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        $this->putFile($path, $file->getRealPath(), $file->getMimeType() ?: 'application/octet-stream', $public);

        return $path;
    }

    /** Upload only the locally optimized media file to object storage. */
    public function storeOptimized(UploadedFile $file, string $directory, string $type, bool $public = false): string
    {
        $path = app(MediaOptimizer::class)->optimizeLocalFile(
            $file->getRealPath(),
            $directory,
            $type,
            $public,
        );

        if (! $path) {
            throw new RuntimeException('Could not optimize the uploaded media file.');
        }

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
            // Objects remain private in S3. Their signed URL is the access
            // control; immutable media can therefore be cached for its URL TTL.
            'CacheControl' => $public ? 'public, max-age=31536000, immutable' : self::PRIVATE_MEDIA_CACHE_CONTROL,
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
        $expires = $this->sharedCdnExpiry($ttlSeconds);
        $signature = rtrim(strtr(base64_encode(md5($secret.$resourcePath.($ip ?? '').$expires, true)), '+/', '-_'), '=');

        return $base.'/md5('.$signature.','.$expires.')'.$resourcePath;
    }

    private function sharedCdnExpiry(int $ttlSeconds): int
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $windowStart = intdiv(time(), self::CDN_SIGNING_WINDOW_SECONDS) * self::CDN_SIGNING_WINDOW_SECONDS;

        return $windowStart + self::CDN_SIGNING_WINDOW_SECONDS + $ttlSeconds;
    }

    public function secureCdnDownloadUrl(string $path, string $filename, int $ttlSeconds = 3600): string
    {
        $base = rtrim((string) config('filesystems.cdn_url'), '/');
        $secret = (string) config('filesystems.cdn_secure_token');
        if ($base === '' || $secret === '') {
            return Storage::disk('s3')->temporaryUrl($path, now()->addSeconds($ttlSeconds), [
                'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
                'ResponseContentType' => 'video/mp4',
            ]);
        }

        return $this->secureCdnUrl($path, $ttlSeconds)
            .'?response-content-disposition='.rawurlencode('attachment; filename="'.$filename.'"')
            .'&response-content-type=video%2Fmp4';
    }
}

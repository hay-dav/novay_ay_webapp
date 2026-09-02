<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaOptimizer
{
    public function optimize(string $path, string $type, bool $public = false): ?string
    {
        $disk = Storage::disk('s3');
        $inputStream = $disk->readStream($path);
        if (! is_resource($inputStream)) {
            return null;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novaya-media-'.Str::uuid();
        mkdir($directory, 0700, true);
        $input = $directory.DIRECTORY_SEPARATOR.'source';
        try {
            $localInput = fopen($input, 'wb');
            stream_copy_to_stream($inputStream, $localInput);
            fclose($localInput);
            fclose($inputStream);

            return $this->optimizeLocalFile($input, dirname($path), $type, $public);
        } finally {
            if (is_resource($inputStream)) {
                fclose($inputStream);
            }
            @unlink($input);
            @rmdir($directory);
        }
    }

    public function optimizeLocalFile(string $input, string $directory, string $type, bool $public = false): ?string
    {
        if (! is_file($input)) {
            return null;
        }

        $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novaya-media-output-'.Str::uuid();
        mkdir($temporaryDirectory, 0700, true);
        $output = $temporaryDirectory.DIRECTORY_SEPARATOR.'optimized.'.$this->extensionFor($type);

        try {
            if (! $this->transcode($input, $output, $type) || ! is_file($output) || filesize($output) === 0) {
                return null;
            }

            $optimizedPath = trim($directory, '/').'/'.Str::uuid().'.'.$this->extensionFor($type);
            Storage::disk('s3')->put($optimizedPath, fopen($output, 'rb'), [
                'visibility' => $public ? 'public' : 'private',
                'ContentType' => $this->mimeFor($type),
                'CacheControl' => $public ? 'public, max-age=31536000, immutable' : 'public, max-age=172800, s-maxage=172800, immutable',
            ]);

            return $optimizedPath;
        } finally {
            @unlink($output);
            @rmdir($temporaryDirectory);
        }
    }

    private function transcode(string $input, string $output, string $type): bool
    {
        if ($type === 'image') {
            $command = [
                'magick',
                $input,
                '-auto-orient',
                '-resize', '1920x1920>',
                '-strip',
                '-quality', '82',
                $output,
            ];
            $escaped = implode(' ', array_map('escapeshellarg', $command));
            exec($escaped.' 2>&1', $ignored, $exitCode);

            return $exitCode === 0;
        }

        $scale = "scale='min(1920,iw)':'min(1920,ih)':force_original_aspect_ratio=decrease";
        $arguments = match ($type) {
            'audio' => ['-vn', '-map_metadata', '-1', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart'],
            'video' => ['-map_metadata', '-1', '-vf', $scale, '-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-maxrate', '5M', '-bufsize', '10M', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart'],
            default => [],
        };
        if ($arguments === []) {
            return false;
        }

        $command = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input], $arguments, [$output]);
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        exec($escaped.' 2>&1', $ignored, $exitCode);

        return $exitCode === 0;
    }

    private function extensionFor(string $type): string
    {
        return match ($type) {
            'image' => 'webp',
            'audio' => 'm4a',
            default => 'mp4',
        };
    }

    private function mimeFor(string $type): string
    {
        return match ($type) {
            'image' => 'image/webp',
            'audio' => 'audio/mp4',
            default => 'video/mp4',
        };
    }
}

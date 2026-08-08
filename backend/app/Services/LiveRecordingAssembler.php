<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LiveRecordingAssembler
{
    public function __construct(private readonly MediaStorage $mediaStorage)
    {
    }

    public function assemble(string|int $stream, int $expectedSegments): string
    {
        $segmentDirectory = "live-recordings/{$stream}/segments";
        $disk = Storage::disk('s3');
        $segments = collect($disk->files($segmentDirectory))
            ->filter(fn (string $path) => preg_match('/\/\d{6}\.(webm|mp4|m4v)$/i', $path))
            ->sort()
            ->values();

        if ($segments->count() !== $expectedSegments) {
            throw new RuntimeException("Получено {$segments->count()} из {$expectedSegments} фрагментов записи.");
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'live-recording-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $listPath = $directory.DIRECTORY_SEPARATOR.'segments.txt';
        $localSegments = [];

        try {
            foreach ($segments as $index => $path) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $localPath = $directory.DIRECTORY_SEPARATOR.sprintf('%06d.%s', $index, $extension);
                $input = $disk->readStream($path);
                if (! is_resource($input)) {
                    throw new RuntimeException("Не удалось прочитать фрагмент записи {$index}.");
                }
                $output = fopen($localPath, 'wb');
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                $localSegments[] = $localPath;
            }

            File::put($listPath, collect($localSegments)
                ->map(fn (string $path) => "file '".str_replace("'", "'\\''", $path)."'")
                ->implode(PHP_EOL).PHP_EOL);

            $sourceExtension = strtolower(pathinfo($localSegments[0], PATHINFO_EXTENSION));
            $sourceExtension = $sourceExtension === 'm4v' ? 'mp4' : $sourceExtension;
            $assembled = $directory.DIRECTORY_SEPARATOR.'assembled.'.$sourceExtension;
            $command = [
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'concat', '-safe', '0', '-i', $listPath,
                '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy',
                $assembled,
            ];
            exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output, $exitCode);
            if ($exitCode !== 0 || ! is_file($assembled) || filesize($assembled) === 0) {
                throw new RuntimeException('Не удалось объединить фрагменты записи: '.implode(' ', $output));
            }

            $contentType = $sourceExtension === 'webm' ? 'video/webm' : 'video/mp4';
            $storedPath = $this->mediaStorage->storeLocalFile(
                $assembled,
                'workouts/videos',
                $sourceExtension,
                $contentType,
            );
            $disk->delete($segments->all());

            return $storedPath;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LiveEgressRecordingProcessor
{
    public function __construct(private readonly MediaStorage $mediaStorage)
    {
    }

    public function process(string $sourcePath): string
    {
        $directory = storage_path('app/live-egress/'.uniqid('', true));
        File::ensureDirectoryExists($directory);
        $input = $directory.'/input.mp4';
        $output = $directory.'/recording.mp4';

        try {
            $read = Storage::disk('s3')->readStream($sourcePath);
            if ($read === false) {
                throw new RuntimeException('Could not read the LiveKit recording from storage.');
            }
            $target = fopen($input, 'wb');
            stream_copy_to_stream($read, $target);
            fclose($target);
            fclose($read);

            if (! $this->hasVideoStream($input)) {
                throw new RuntimeException('The LiveKit recording does not contain a video stream.');
            }

            $font = '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf';
            // Room Composite is already a fixed 16:9 canvas. Cropping based
            // on its opening frames is unsafe because Egress begins before
            // tracks are fully subscribed, often with a black frame.
            $horizontalFilter = 'split=2[background_source][foreground_source];'
                .'[background_source]scale=720:1280:force_original_aspect_ratio=increase,'
                .'crop=720:1280,boxblur=24:8[background];'
                .'[foreground_source]scale=720:1280:force_original_aspect_ratio=decrease[foreground];'
                .'[background][foreground]overlay=(W-w)/2:(H-h)/2,'
                ."drawtext=fontfile={$font}:text='НОВАЯ Я | Курс Лазаревой':fontcolor=white@0.9:fontsize=26:borderw=1:bordercolor=0x572369@1:shadowcolor=black@0.9:shadowx=2:shadowy=2:x=w-tw-34:y=h-th-30[outv]";
            $command = sprintf(
                'ffmpeg -y -hide_banner -loglevel error -i %s '
                .'-filter_complex %s -map %s -map 0:a:0? -c:v libx264 -preset medium -crf 21 -maxrate 4M -bufsize 8M -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
                escapeshellarg($input),
                escapeshellarg($horizontalFilter),
                escapeshellarg('[outv]'),
                escapeshellarg($output),
            );
            exec($command, $lines, $exitCode);
            if ($exitCode !== 0 || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('Could not prepare the horizontal watermarked recording: '.implode("\n", $lines));
            }

            if (! $this->hasVideoStream($output)) {
                throw new RuntimeException('The processed live recording does not contain a video stream.');
            }

            return $this->mediaStorage->storeLocalFile($output, 'workouts/videos', 'mp4', 'video/mp4');
        }
        finally {
            File::deleteDirectory($directory);
        }
    }

    private function hasVideoStream(string $path): bool
    {
        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_type -of csv=p=0 %s 2>&1',
            escapeshellarg($path),
        );
        exec($command, $lines, $exitCode);

        return $exitCode === 0 && trim(implode("\n", $lines)) === 'video';
    }

}

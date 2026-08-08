<?php

namespace App\Jobs;

use App\Services\MediaOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class OptimizeStoredMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;

    public function __construct(
        public string $modelClass,
        public int $modelId,
        public string $column,
        public string $sourcePath,
        public string $type,
        public bool $public = false,
    ) {
        $this->onQueue('media');
    }

    public function handle(MediaOptimizer $optimizer): void
    {
        $model = $this->modelClass::query()->find($this->modelId);
        if (! $model || $model->getAttribute($this->column) !== $this->sourcePath) {
            return;
        }

        $optimizedPath = $optimizer->optimize($this->sourcePath, $this->type, $this->public);
        if (! $optimizedPath) {
            return;
        }

        $model->forceFill([$this->column => $optimizedPath])->save();
        Storage::disk('s3')->delete($this->sourcePath);
    }
}

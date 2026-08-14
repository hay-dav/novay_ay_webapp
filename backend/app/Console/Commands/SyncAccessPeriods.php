<?php

namespace App\Console\Commands;

use App\Services\AccessPeriodService;
use Illuminate\Console\Command;

class SyncAccessPeriods extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Activate and expire scheduled client access periods';

    public function handle(AccessPeriodService $service): int
    {
        $result = $service->synchronize();
        $this->info("Activated: {$result['activated']}; expired: {$result['expired']}");

        return self::SUCCESS;
    }
}

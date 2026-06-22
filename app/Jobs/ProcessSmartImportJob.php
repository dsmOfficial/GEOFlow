<?php

namespace App\Jobs;

use App\Models\SmartImportJob;
use App\Services\GeoFlow\SmartImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSmartImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $smartImportJobId
    ) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'geoflow',
            'smart_import',
            'smart_import_job:'.$this->smartImportJobId,
        ];
    }

    public function handle(SmartImportService $service): void
    {
        $job = SmartImportJob::query()->find($this->smartImportJobId);

        if (! $job) {
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        $service->handle($job);
    }
}

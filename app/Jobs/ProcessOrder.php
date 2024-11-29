<?php

namespace App\Jobs;

use App\Services\VeryfiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $orderFilePath,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(VeryfiService $veryfiService): void
    {
        $result = $veryfiService->run($this->orderFilePath);
        info($result);
    }
}

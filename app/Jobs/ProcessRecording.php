<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public string $recordingId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(): void
    {
        // Implemented in a later task.
    }
}

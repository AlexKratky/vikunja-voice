<?php

namespace App\Jobs;

use App\Services\KumaPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\Log;

/**
 * Queued by the scheduler and handled by the worker, so the push only reaches
 * Uptime Kuma while both are running. A missing push is what raises the alert.
 */
class KumaHeartbeat implements ShouldQueue
{
    use Queueable;

    // A heartbeat that failed is just a missed beat; retrying it would only
    // pile up stale pushes, and it must never land in failed_jobs itself.
    public int $tries = 1;

    public int $timeout = 30;

    public float $queuedAt;

    public function __construct()
    {
        $this->queuedAt = microtime(true);
    }

    public function handle(KumaPush $kuma): void
    {
        try {
            $failer = app('queue.failer');
            $failed = $failer instanceof CountableFailedJobProvider ? $failer->count() : count($failer->all());
        } catch (\Throwable $e) {
            // Skip the push: Kuma alerts on the missing beat.
            Log::warning('Kuma heartbeat could not count failed jobs', ['error' => $e->getMessage()]);

            return;
        }

        // Time spent waiting in the queue shows up as "ping" in Kuma.
        $latencyMs = (int) round((microtime(true) - $this->queuedAt) * 1000);

        if ($failed > 0) {
            $kuma->send(false, trans_choice('voice.kuma_failed_jobs', $failed, ['count' => $failed], config('voice.locale')), $latencyMs);

            return;
        }

        $kuma->send(true, 'OK', $latencyMs);
    }
}

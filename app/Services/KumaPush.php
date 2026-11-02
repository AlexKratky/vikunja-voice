<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KumaPush
{
    public static function enabled(): bool
    {
        return (string) config('voice.kuma.push_url') !== '';
    }

    public function send(bool $up, string $message, ?int $pingMs = null): void
    {
        if (! self::enabled()) {
            return;
        }

        // The URL copied from Kuma already carries ?status=up&msg=OK&ping=;
        // drop it and send our own values.
        $url = strtok((string) config('voice.kuma.push_url'), '?');

        try {
            Http::timeout(10)
                ->get($url, [
                    'status' => $up ? 'up' : 'down',
                    'msg' => $message,
                    'ping' => $pingMs ?? '',
                ])
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('Uptime Kuma push failed', ['error' => $e->getMessage()]);
        }
    }
}

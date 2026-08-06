<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Ntfy
{
    public function send(string $message): void
    {
        $topic = (string) config('voice.ntfy.topic');

        if ($topic === '') {
            return;
        }

        try {
            Http::timeout(10)
                ->withBody($message, 'text/plain; charset=utf-8')
                ->post(config('voice.ntfy.url').'/'.$topic)
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('ntfy notification failed', ['error' => $e->getMessage()]);
        }
    }
}

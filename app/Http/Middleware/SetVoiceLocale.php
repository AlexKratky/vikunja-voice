<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetVoiceLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale((string) config('voice.locale'));

        return $next($request);
    }
}

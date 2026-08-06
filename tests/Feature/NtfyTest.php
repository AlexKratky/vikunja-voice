<?php

use App\Services\Ntfy;
use Illuminate\Support\Facades\Http;

it('sends message to configured topic', function () {
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'my-topic');
    Http::fake();

    (new Ntfy)->send('✅ Nakoupit mléko');

    Http::assertSent(fn ($r) => $r->url() === 'https://ntfy.test/my-topic'
        && $r->body() === '✅ Nakoupit mléko');
});

it('does nothing when topic is empty', function () {
    config()->set('voice.ntfy.topic', '');
    Http::fake();

    (new Ntfy)->send('zpráva');

    Http::assertNothingSent();
});

it('swallows network errors', function () {
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'my-topic');
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

    (new Ntfy)->send('zpráva');

    expect(true)->toBeTrue(); // reached without throwing
});

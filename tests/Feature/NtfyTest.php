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

it('sends click url as X-Click header', function () {
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'my-topic');
    Http::fake();

    (new Ntfy)->send('✅ Nakoupit mléko', 'https://vikunja.test/tasks/42');

    Http::assertSent(fn ($r) => $r->url() === 'https://ntfy.test/my-topic'
        && $r->header('X-Click') === ['https://vikunja.test/tasks/42']);
});

it('omits X-Click header without click url', function () {
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'my-topic');
    Http::fake();

    (new Ntfy)->send('✅ Nakoupit mléko');

    Http::assertSent(fn ($r) => ! $r->hasHeader('X-Click'));
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

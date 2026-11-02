<?php

use App\Jobs\KumaHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('voice.kuma.push_url', 'https://kuma.test/api/push/abc123?status=up&msg=OK&ping=');
});

function failJob(): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: Gemini down',
    ]);
}

function heartbeatEvent()
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, KumaHeartbeat::class));
}

it('pushes up with OK when there are no failed jobs', function () {
    Http::fake();

    dispatch_sync(new KumaHeartbeat);

    Http::assertSent(function ($r) {
        parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);

        return str_starts_with($r->url(), 'https://kuma.test/api/push/abc123?')
            && $query['status'] === 'up'
            && $query['msg'] === 'OK'
            && is_numeric($query['ping']);
    });
});

it('pushes down with the number of failed jobs', function () {
    Http::fake();
    failJob();
    failJob();

    dispatch_sync(new KumaHeartbeat);

    Http::assertSent(function ($r) {
        parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);

        return $query['status'] === 'down'
            && $query['msg'] === '2 recordings failed, see failed_jobs';
    });
});

it('uses the configured locale for the down message', function () {
    config()->set('voice.locale', 'cs');
    Http::fake();
    foreach (range(1, 5) as $_) {
        failJob();
    }

    dispatch_sync(new KumaHeartbeat);

    Http::assertSent(function ($r) {
        parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);

        return $query['msg'] === '5 nahrávek selhalo, viz failed_jobs';
    });
});

it('sends nothing when the push url is empty', function () {
    config()->set('voice.kuma.push_url', '');
    Http::fake();

    dispatch_sync(new KumaHeartbeat);

    Http::assertNothingSent();
});

it('swallows network errors so the heartbeat never lands in failed_jobs', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    dispatch_sync(new KumaHeartbeat);

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('is scheduled every five minutes only when a push url is set', function () {
    $event = heartbeatEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    config()->set('voice.kuma.push_url', '');

    expect($event->filtersPass(app()))->toBeFalse();
});

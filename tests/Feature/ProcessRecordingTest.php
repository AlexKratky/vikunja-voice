<?php

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use App\Services\GeminiClient;
use App\Services\Ntfy;
use App\Services\VikunjaClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

beforeEach(function () {
    config()->set('voice.vikunja.url', 'https://vikunja.test');
    config()->set('voice.vikunja.token', 'vik-token');
    config()->set('voice.vikunja.default_project_id', 1);
    config()->set('voice.gemini.api_key', 'test-key');
    config()->set('voice.gemini.model', 'gemini-2.5-flash');
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'topic');
    config()->set('voice.ntfy.notify_success', true);
    config()->set('voice.vikunja.auto_assign_username', null);
    config()->set('voice.vikunja.auto_label', null);

    Storage::fake('local');
});

afterEach(function () {
    SentrySdk::setCurrentHub(new Hub);
});

function makeRecording(): Recording
{
    $recording = Recording::factory()->create(['path' => 'recordings/test.webm']);
    Storage::disk('local')->put('recordings/test.webm', 'AUDIO');

    return $recording;
}

function fakeApis(array $gemini, array $overrides = []): void
{
    Http::fake(array_merge([
        'vikunja.test/api/v1/projects' => Http::response([
            ['id' => 1, 'title' => 'Inbox'],
            ['id' => 5, 'title' => 'Nákupy'],
        ]),
        'vikunja.test/api/v1/labels' => Http::response([['id' => 9, 'title' => 'doma']]),
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($gemini)]]]]],
        ]),
        'vikunja.test/api/v1/projects/*/tasks' => Http::response(['id' => 42]),
        'vikunja.test/api/v1/tasks/42/labels' => Http::response([]),
        'vikunja.test/api/v1/tasks/42/assignees' => Http::response([]),
        'vikunja.test/api/v1/users*' => Http::response([['id' => 1, 'username' => 'alex']]),
        'ntfy.test/*' => Http::response(''),
    ], $overrides));
}

function geminiPayload(array $overrides = []): array
{
    return array_merge([
        'transcript' => 'nakoupit mléko doma štítek',
        'title' => 'Nakoupit mléko',
        'description' => 'Plnotučné',
        'due_date' => '2026-08-08T23:59:00+02:00',
        'priority' => 3,
        'project_id' => 5,
        'label_ids' => [9],
    ], $overrides);
}

it('processes a recording end to end', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    $recording->refresh();
    expect($recording->status)->toBe('done')
        ->and($recording->transcript)->toBe('nakoupit mléko doma štítek')
        ->and($recording->vikunja_task_id)->toBe(42);

    Http::assertSent(function ($r) {
        if ($r->method() !== 'PUT' || ! str_contains($r->url(), '/projects/5/tasks')) {
            return false;
        }
        $data = $r->data();

        return $data['title'] === 'Nakoupit mléko'
            && $data['due_date'] === '2026-08-08T23:59:00+02:00'
            && $data['priority'] === 3
            && str_contains($data['description'], 'Plnotučné')
            && str_contains($data['description'], 'nakoupit mléko doma štítek');
    });

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels')
        && $r->data()['label_id'] === 9);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic')
        && str_contains($r->body(), 'Nakoupit mléko')
        && $r->header('X-Click') === ['https://vikunja.test/tasks/42']);
});

it('falls back to default project for unknown project id', function () {
    fakeApis(geminiPayload(['project_id' => 999, 'label_ids' => []]));
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/1/tasks'));
});

it('omits due_date when null', function () {
    fakeApis(geminiPayload(['due_date' => null, 'label_ids' => []]));
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && ! array_key_exists('due_date', $r->data()));
});

it('propagates Gemini failures so the queue retries', function () {
    fakeApis(geminiPayload(), ['generativelanguage.googleapis.com/*' => Http::response('', 500)]);
    $recording = makeRecording();

    expect(fn () => (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    ))->toThrow(RuntimeException::class);

    expect($recording->refresh()->status)->toBe('processing');
});

it('does not fail the task when label attach fails', function () {
    fakeApis(geminiPayload(), ['vikunja.test/api/v1/tasks/42/labels' => Http::response('', 500)]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    expect($recording->refresh()->status)->toBe('done');
});

it('attaches the auto label case-insensitively beside extracted ones', function () {
    config()->set('voice.vikunja.auto_label', 'voice');
    fakeApis(geminiPayload(), [
        'vikunja.test/api/v1/labels' => Http::response([
            ['id' => 9, 'title' => 'doma'],
            ['id' => 77, 'title' => 'Voice'],
        ]),
    ]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels') && $r->data()['label_id'] === 9);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels') && $r->data()['label_id'] === 77);
});

it('does not attach the auto label twice when already extracted', function () {
    config()->set('voice.vikunja.auto_label', 'Voice');
    fakeApis(geminiPayload(['label_ids' => [77]]), [
        'vikunja.test/api/v1/labels' => Http::response([
            ['id' => 9, 'title' => 'doma'],
            ['id' => 77, 'title' => 'Voice'],
        ]),
    ]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    $attaches = Http::recorded(fn ($r) => str_contains($r->url(), '/tasks/42/labels'));
    expect(count($attaches))->toBe(1);
});

it('completes when the auto label does not exist in Vikunja', function () {
    config()->set('voice.vikunja.auto_label', 'Missing');
    fakeApis(geminiPayload(['label_ids' => []]));
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    expect($recording->refresh()->status)->toBe('done');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels'));
});

it('assigns the configured user to the created task', function () {
    config()->set('voice.vikunja.auto_assign_username', 'alex');
    fakeApis(geminiPayload());
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/tasks/42/assignees')
        && $r->data()['user_id'] === 1);
});

it('does not assign anyone when no auto assign user is configured', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/assignees'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/users'));
});

it('does not fail the task when assignment fails', function () {
    config()->set('voice.vikunja.auto_assign_username', 'alex');
    fakeApis(geminiPayload(), [
        'vikunja.test/api/v1/tasks/42/assignees' => Http::response('', 500),
    ]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    expect($recording->refresh()->status)->toBe('done');
});

it('skips assignment when the username is not found', function () {
    config()->set('voice.vikunja.auto_assign_username', 'ghost');
    fakeApis(geminiPayload(), [
        'vikunja.test/api/v1/users*' => Http::response('null'),
    ]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    expect($recording->refresh()->status)->toBe('done');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/assignees'));
});

it('skips already-done recordings', function () {
    Http::fake();
    $recording = Recording::factory()->create(['status' => 'done']);

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertNothingSent();
});

it('marks recording failed and notifies via failed hook', function () {
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->failed(new RuntimeException('Gemini API error: HTTP 500'));

    $recording->refresh();
    expect($recording->status)->toBe('failed')
        ->and($recording->error)->toContain('HTTP 500');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic')
        && str_contains($r->body(), 'Voice note processing failed'));
});

it('sends the failure notification in Czech when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->failed(new RuntimeException('boom'));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic')
        && str_contains($r->body(), 'Zpracování nahrávky selhalo'));
});

it('labels the transcript in English by default', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Transcript:'));
});

it('labels the transcript in Czech when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    fakeApis(geminiPayload());
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Přepis:'));
});

function fakeSentryHub(Scope $scope, &$captured): void
{
    // Ignore unrelated hub calls (sentry-laravel probes getClient() on app
    // terminate); only withScope/captureException carry expectations.
    $hub = Mockery::mock(HubInterface::class)->shouldIgnoreMissing();
    $hub->shouldReceive('withScope')->once()->andReturnUsing(fn (callable $cb) => $cb($scope));
    $hub->shouldReceive('captureException')->once()->andReturnUsing(function ($e) use (&$captured) {
        $captured = $e;

        return null;
    });
    SentrySdk::setCurrentHub($hub);
}

it('captures a fatal terminal-failure event in Sentry when all attempts are exhausted', function () {
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeRecording();

    $exception = new RuntimeException('Gemini API error: HTTP 500');
    $scope = new Scope;
    $captured = null;
    fakeSentryHub($scope, $captured);

    (new ProcessRecording($recording->id))->failed($exception);

    expect($captured)->toBe($exception);

    $event = $scope->applyToEvent(Event::createEvent());
    expect($event->getTags())->toMatchArray([
        'job' => 'process_recording',
        'terminal_failure' => 'true',
    ])
        ->and((string) $event->getLevel())->toBe('fatal')
        ->and($event->getContexts()['recording']['id'])->toBe($recording->id)
        ->and($event->getContexts()['recording']['error'])->toBe('Gemini API error: HTTP 500');

    // Existing side effects still happen.
    expect($recording->refresh()->status)->toBe('failed');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic'));
});

it('captures a fallback exception when failed() receives null', function () {
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeRecording();

    $scope = new Scope;
    $captured = null;
    fakeSentryHub($scope, $captured);

    (new ProcessRecording($recording->id))->failed(null);

    expect($captured)->toBeInstanceOf(RuntimeException::class)
        ->and($captured->getMessage())->toBe('ProcessRecording failed without exception');
});

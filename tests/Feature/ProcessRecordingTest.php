<?php

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use App\Services\GeminiClient;
use App\Services\Ntfy;
use App\Services\VikunjaClient;
use Illuminate\Http\Client\RequestException;
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

function makeNote(string $text = 'zítra koupit mléko'): Recording
{
    return Recording::factory()->note($text)->create();
}

function runJob(Recording $recording): void
{
    (new ProcessRecording($recording->id))->handle(
        app(GeminiClient::class),
        app(VikunjaClient::class),
        app(Ntfy::class),
    );
}

/**
 * Http::fake() merges stubs across calls and invokes every matching stub
 * before taking the first non-null response. Hence: one fake() per test,
 * overrides first ($overrides + $defaults) so they win by position, and the
 * stateful create-task sequence under a single pattern only.
 */
function fakeApis(array $gemini, array $overrides = [], array $taskIds = [42]): void
{
    $createTask = Http::sequence();
    foreach ($taskIds as $id) {
        $createTask->push(['id' => $id]);
    }

    Http::fake($overrides + [
        'vikunja.test/api/v1/projects' => Http::response([
            ['id' => 1, 'title' => 'Inbox'],
            ['id' => 5, 'title' => 'Nákupy'],
        ]),
        'vikunja.test/api/v1/labels' => Http::response([['id' => 9, 'title' => 'doma']]),
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($gemini)]]]]],
        ]),
        'vikunja.test/api/v1/projects/*/tasks' => $createTask,
        'vikunja.test/api/v1/tasks/*/labels' => Http::response([]),
        'vikunja.test/api/v1/tasks/*/assignees' => Http::response([]),
        'vikunja.test/api/v1/users*' => Http::response([['id' => 1, 'username' => 'alex']]),
        'ntfy.test/*' => Http::response(''),
    ]);
}

function geminiTask(array $overrides = []): array
{
    return array_merge([
        'transcript_segment' => null,
        'title' => 'Nakoupit mléko',
        'description' => 'Plnotučné',
        'due_date' => '2026-08-08T23:59:00+02:00',
        'priority' => 3,
        'project_id' => 5,
        'label_ids' => [9],
    ], $overrides);
}

function geminiPayload(array $taskOverrides = [], array $overrides = []): array
{
    return array_merge([
        'transcript' => 'nakoupit mléko doma štítek',
        'tasks' => [geminiTask($taskOverrides)],
    ], $overrides);
}

function multiTaskPayload(): array
{
    return [
        'transcript' => 'Koupit mléko a chleba, ideálně dnes. Založ další úkol. Zavolat doktorovi kvůli objednání, je to urgentní. Další úkol. Vyzvednout balík.',
        'tasks' => [
            geminiTask([
                'transcript_segment' => 'Koupit mléko a chleba, ideálně dnes',
                'title' => 'Koupit mléko a chleba',
                'description' => null,
                'priority' => null,
                'project_id' => 5,
                'label_ids' => [],
            ]),
            geminiTask([
                'transcript_segment' => 'Zavolat doktorovi kvůli objednání, je to urgentní',
                'title' => 'Zavolat doktorovi',
                'description' => 'Kvůli objednání',
                'due_date' => null,
                'priority' => 4,
                'project_id' => null,
                'label_ids' => [9],
            ]),
            geminiTask([
                'transcript_segment' => 'Vyzvednout balík',
                'title' => 'Vyzvednout balík',
                'description' => null,
                'due_date' => null,
                'priority' => null,
                'project_id' => 5,
                'label_ids' => [],
            ]),
        ],
    ];
}

function createTaskRequests(): array
{
    return Http::recorded(fn ($request) => preg_match('#/projects/\d+/tasks$#', $request->url()) === 1)
        ->map(fn ($pair) => $pair[0])
        ->values()
        ->all();
}

it('processes a recording end to end', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();

    runJob($recording);

    $recording->refresh();
    expect($recording->status)->toBe('done')
        ->and($recording->transcript)->toBe('nakoupit mléko doma štítek')
        ->and($recording->vikunja_task_ids)->toBe([42])
        ->and($recording->extraction['tasks'])->toHaveCount(1);

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

it('keeps the whole transcript and no task marker for a single task', function () {
    fakeApis(geminiPayload(['transcript_segment' => 'only a part']));
    $recording = makeRecording();

    runJob($recording);

    [$create] = createTaskRequests();
    expect($create->data()['description'])
        ->toBe('<p>Plnotučné</p><hr><p><em>Transcript:</em> nakoupit mléko doma štítek</p>');
});

it('creates one Vikunja task per extracted task with its own transcript segment', function () {
    fakeApis(multiTaskPayload(), taskIds: [42, 43, 44]);
    $recording = makeRecording();

    runJob($recording);

    $recording->refresh();
    expect($recording->status)->toBe('done')
        ->and($recording->vikunja_task_ids)->toBe([42, 43, 44])
        ->and($recording->transcript)->toBe(multiTaskPayload()['transcript']);

    $creates = createTaskRequests();
    expect($creates)->toHaveCount(3);
    [$first, $second, $third] = $creates;

    expect($first->url())->toEndWith('/projects/5/tasks')
        ->and($first->data()['title'])->toBe('Koupit mléko a chleba')
        ->and($first->data()['due_date'])->toBe('2026-08-08T23:59:00+02:00')
        ->and($first->data()['description'])
        ->toBe('<hr><p><em>Transcript (task 1/3):</em> Koupit mléko a chleba, ideálně dnes</p>');

    // Unknown project id falls back to the default project.
    expect($second->url())->toEndWith('/projects/1/tasks')
        ->and($second->data()['title'])->toBe('Zavolat doktorovi')
        ->and($second->data()['priority'])->toBe(4)
        ->and($second->data())->not->toHaveKey('due_date')
        ->and($second->data()['description'])
        ->toBe('<p>Kvůli objednání</p><hr><p><em>Transcript (task 2/3):</em> Zavolat doktorovi kvůli objednání, je to urgentní</p>');

    expect($third->url())->toEndWith('/projects/5/tasks')
        ->and($third->data()['description'])
        ->toBe('<hr><p><em>Transcript (task 3/3):</em> Vyzvednout balík</p>');

    // Labels are attached to the right task only.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/43/labels') && $r->data()['label_id'] === 9);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/44/labels'));
});

it('sends one ntfy notification per created task', function () {
    fakeApis(multiTaskPayload(), taskIds: [42, 43, 44]);
    $recording = makeRecording();

    runJob($recording);

    foreach ([42 => 'Koupit mléko a chleba', 43 => 'Zavolat doktorovi', 44 => 'Vyzvednout balík'] as $id => $title) {
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic')
            && str_contains($r->body(), $title)
            && $r->header('X-Click') === ["https://vikunja.test/tasks/{$id}"]);
    }

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'ntfy.test')))->toHaveCount(3);
});

it('resumes after a partial failure without calling Gemini again', function () {
    $payload = multiTaskPayload();
    $payload['tasks'] = array_slice($payload['tasks'], 0, 2);

    fakeApis($payload, [
        'vikunja.test/api/v1/projects/*/tasks' => Http::sequence()
            ->push(['id' => 42])
            ->pushStatus(500)
            ->push(['id' => 43]),
    ]);
    $recording = makeRecording();

    expect(fn () => runJob($recording))->toThrow(RequestException::class);

    $recording->refresh();
    expect($recording->status)->toBe('processing')
        ->and($recording->vikunja_task_ids)->toBe([42])
        ->and($recording->extraction['tasks'])->toHaveCount(2)
        ->and($recording->transcript)->toBe($payload['transcript']);

    runJob($recording);

    $recording->refresh();
    expect($recording->status)->toBe('done')
        ->and($recording->vikunja_task_ids)->toBe([42, 43]);

    // The second pass reused the stored extraction: no Gemini, no Vikunja lookups.
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'generativelanguage')))->toHaveCount(1)
        ->and(Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/v1/projects')))->toHaveCount(1)
        ->and(Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/v1/labels')))->toHaveCount(1)
        ->and(createTaskRequests())->toHaveCount(3);

    // Each task was announced exactly once.
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'ntfy.test')))->toHaveCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test') && $r->header('X-Click') === ['https://vikunja.test/tasks/43']);
});

it('refuses to re-extract when tasks exist but the stored extraction is unusable', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();
    $recording->update([
        'extraction' => ['transcript' => 'x', 'tasks' => []],
        'vikunja_task_ids' => [42],
    ]);

    expect(fn () => runJob($recording))->toThrow(RuntimeException::class, 'no usable extraction');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'generativelanguage'));
    expect(createTaskRequests())->toHaveCount(0);
});

it('still creates one task from the old flat response shape', function () {
    fakeApis([
        'transcript' => 'nakoupit mléko',
        'title' => 'Nakoupit mléko',
        'project_id' => 5,
        'label_ids' => [],
    ]);
    $recording = makeRecording();

    runJob($recording);

    expect($recording->refresh()->vikunja_task_ids)->toBe([42]);

    [$create] = createTaskRequests();
    expect($create->url())->toEndWith('/projects/5/tasks')
        ->and($create->data()['title'])->toBe('Nakoupit mléko')
        ->and($create->data()['description'])->toBe('<hr><p><em>Transcript:</em> nakoupit mléko</p>');
});

it('uses a localized fallback title when the model returns none', function () {
    fakeApis(geminiPayload(['title' => '']));
    $recording = makeRecording();

    runJob($recording);

    [$create] = createTaskRequests();
    expect($create->data()['title'])->toStartWith('Recording ');
});

it('uses the Czech fallback title when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    fakeApis(geminiPayload(['title' => '']));
    $recording = makeRecording();

    runJob($recording);

    [$create] = createTaskRequests();
    expect($create->data()['title'])->toStartWith('Nahrávka ');
});

it('falls back to default project for unknown project id', function () {
    fakeApis(geminiPayload(['project_id' => 999, 'label_ids' => []]));
    $recording = makeRecording();

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/1/tasks'));
});

it('omits due_date when null', function () {
    fakeApis(geminiPayload(['due_date' => null, 'label_ids' => []]));
    $recording = makeRecording();

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && ! array_key_exists('due_date', $r->data()));
});

it('propagates Gemini failures so the queue retries', function () {
    fakeApis(geminiPayload(), ['generativelanguage.googleapis.com/*' => Http::response('', 500)]);
    $recording = makeRecording();

    expect(fn () => runJob($recording))->toThrow(RuntimeException::class);

    $recording->refresh();
    expect($recording->status)->toBe('processing')
        ->and($recording->extraction)->toBeNull()
        ->and($recording->vikunja_task_ids)->toBeNull();
});

it('does not fail the task when label attach fails', function () {
    fakeApis(geminiPayload(), ['vikunja.test/api/v1/tasks/42/labels' => Http::response('', 500)]);
    $recording = makeRecording();

    runJob($recording);

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

    runJob($recording);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels') && $r->data()['label_id'] === 9);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels') && $r->data()['label_id'] === 77);

    // Baked into the stored extraction so a retry needs no label lookup.
    expect($recording->refresh()->extraction['tasks'][0]['label_ids'])->toBe([9, 77]);
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

    runJob($recording);

    $attaches = Http::recorded(fn ($r) => str_contains($r->url(), '/tasks/42/labels'));
    expect(count($attaches))->toBe(1);
});

it('completes when the auto label does not exist in Vikunja', function () {
    config()->set('voice.vikunja.auto_label', 'Missing');
    fakeApis(geminiPayload(['label_ids' => []]));
    $recording = makeRecording();

    runJob($recording);

    expect($recording->refresh()->status)->toBe('done');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/42/labels'));
});

it('assigns the configured user to the created task', function () {
    config()->set('voice.vikunja.auto_assign_username', 'alex');
    fakeApis(geminiPayload());
    $recording = makeRecording();

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/tasks/42/assignees')
        && $r->data()['user_id'] === 1);
});

it('does not assign anyone when no auto assign user is configured', function () {
    fakeApis(geminiPayload());
    $recording = makeRecording();

    runJob($recording);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/assignees'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/users'));
});

it('does not fail the task when assignment fails', function () {
    config()->set('voice.vikunja.auto_assign_username', 'alex');
    fakeApis(geminiPayload(), [
        'vikunja.test/api/v1/tasks/42/assignees' => Http::response('', 500),
    ]);
    $recording = makeRecording();

    runJob($recording);

    expect($recording->refresh()->status)->toBe('done');
});

it('skips assignment when the username is not found', function () {
    config()->set('voice.vikunja.auto_assign_username', 'ghost');
    fakeApis(geminiPayload(), [
        'vikunja.test/api/v1/users*' => Http::response('null'),
    ]);
    $recording = makeRecording();

    runJob($recording);

    expect($recording->refresh()->status)->toBe('done');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/assignees'));
});

it('skips already-done recordings', function () {
    Http::fake();
    $recording = Recording::factory()->create([
        'status' => 'done',
        'extraction' => ['transcript' => 'x', 'tasks' => [['title' => 'x']]],
        'vikunja_task_ids' => [42],
    ]);

    runJob($recording);

    Http::assertNothingSent();
});

it('marks recording failed and notifies via failed hook', function () {
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeRecording();
    $recording->update(['extraction' => ['transcript' => 'x', 'tasks' => [['title' => 'x']]], 'vikunja_task_ids' => [42]]);

    (new ProcessRecording($recording->id))->failed(new RuntimeException('Gemini API error: HTTP 500'));

    $recording->refresh();
    expect($recording->status)->toBe('failed')
        ->and($recording->error)->toContain('HTTP 500')
        // Kept so a manual queue:retry resumes from the first missing task.
        ->and($recording->vikunja_task_ids)->toBe([42])
        ->and($recording->extraction['tasks'])->toHaveCount(1);

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

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Transcript:'));
});

it('labels the transcript in Czech when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    fakeApis(geminiPayload());
    $recording = makeRecording();

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Přepis:'));
});

it('labels transcript segments in Czech with the task position when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    fakeApis(multiTaskPayload(), taskIds: [42, 43, 44]);
    $recording = makeRecording();

    runJob($recording);

    [$first] = createTaskRequests();
    expect($first->data()['description'])->toContain('<em>Přepis (úkol 1/3):</em>');
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

it('processes a typed note end to end without touching storage', function () {
    // The model echoes a slightly different transcript; the typed text wins.
    fakeApis(geminiPayload(overrides: ['transcript' => 'Zítra koupit mléko.']));
    $recording = makeNote('  zítra koupit mléko  ');

    runJob($recording);

    $recording->refresh();
    expect($recording->status)->toBe('done')
        ->and($recording->transcript)->toBe('zítra koupit mléko')
        ->and($recording->extraction['transcript'])->toBe('zítra koupit mléko')
        ->and($recording->vikunja_task_ids)->toBe([42]);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'generativelanguage.googleapis.com')) {
            return false;
        }
        $parts = $r->data()['contents'][0]['parts'];

        return ! isset($parts[0]['inlineData'])
            && str_contains($parts[0]['text'], 'zítra koupit mléko')
            && str_contains($parts[1]['text'], 'typed note');
    });

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Note:')
        && str_contains($r->data()['description'], 'zítra koupit mléko'));

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('labels a typed note in Czech when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    fakeApis(geminiPayload());
    $recording = makeNote();

    runJob($recording);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && str_contains($r->data()['description'], 'Poznámka:'));
});

it('splits a typed note into several tasks with numbered note labels', function () {
    fakeApis(multiTaskPayload(), taskIds: [101, 102, 103]);
    $recording = makeNote('Koupit mléko a chleba, ideálně dnes. Další úkol. Zavolat doktorovi. Další úkol. Vyzvednout balík.');

    runJob($recording);

    $requests = createTaskRequests();
    expect($requests)->toHaveCount(3)
        ->and($requests[0]->data()['description'])->toContain('Note (task 1/3):')
        ->and($requests[2]->data()['description'])->toContain('Vyzvednout balík')
        ->and($recording->refresh()->vikunja_task_ids)->toBe([101, 102, 103]);
});

it('marks a typed note failed and notifies via failed hook', function () {
    Http::fake(['ntfy.test/*' => Http::response('')]);
    $recording = makeNote();

    (new ProcessRecording($recording->id))->failed(new RuntimeException('Gemini API error: HTTP 500'));

    expect($recording->refresh()->status)->toBe('failed')
        ->and($recording->text)->toBe('zítra koupit mléko');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'ntfy.test/topic')
        && str_contains($r->body(), 'HTTP 500'));
});

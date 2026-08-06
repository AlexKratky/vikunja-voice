<?php

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('voice.vikunja.url', 'https://vikunja.test');
    config()->set('voice.vikunja.token', 'vik-token');
    config()->set('voice.vikunja.default_project_id', 1);
    config()->set('voice.gemini.api_key', 'test-key');
    config()->set('voice.gemini.model', 'gemini-2.5-flash');
    config()->set('voice.ntfy.url', 'https://ntfy.test');
    config()->set('voice.ntfy.topic', 'topic');
    config()->set('voice.ntfy.notify_success', true);

    Storage::fake('local');
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
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
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
        && str_contains($r->body(), 'Nakoupit mléko'));
});

it('falls back to default project for unknown project id', function () {
    fakeApis(geminiPayload(['project_id' => 999, 'label_ids' => []]));
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/1/tasks'));
});

it('omits due_date when null', function () {
    fakeApis(geminiPayload(['due_date' => null, 'label_ids' => []]));
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
    );

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/projects/5/tasks')
        && ! array_key_exists('due_date', $r->data()));
});

it('propagates Gemini failures so the queue retries', function () {
    fakeApis(geminiPayload(), ['generativelanguage.googleapis.com/*' => Http::response('', 500)]);
    $recording = makeRecording();

    expect(fn () => (new ProcessRecording($recording->id))->handle(
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
    ))->toThrow(RuntimeException::class);

    expect($recording->refresh()->status)->toBe('processing');
});

it('does not fail the task when label attach fails', function () {
    fakeApis(geminiPayload(), ['vikunja.test/api/v1/tasks/42/labels' => Http::response('', 500)]);
    $recording = makeRecording();

    (new ProcessRecording($recording->id))->handle(
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
    );

    expect($recording->refresh()->status)->toBe('done');
});

it('skips already-done recordings', function () {
    Http::fake();
    $recording = Recording::factory()->create(['status' => 'done']);

    (new ProcessRecording($recording->id))->handle(
        app(App\Services\GeminiClient::class),
        app(App\Services\VikunjaClient::class),
        app(App\Services\Ntfy::class),
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
        && str_contains($r->body(), 'selhalo'));
});

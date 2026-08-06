<?php

use App\Services\VikunjaClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('voice.vikunja.url', 'https://vikunja.test');
    config()->set('voice.vikunja.token', 'vik-token');
});

it('fetches projects with auth header', function () {
    Http::fake([
        'vikunja.test/api/v1/projects' => Http::response([
            ['id' => 1, 'title' => 'Inbox', 'description' => ''],
            ['id' => 5, 'title' => 'Nákupy', 'description' => ''],
        ]),
    ]);

    $projects = (new VikunjaClient)->projects();

    expect($projects)->toBe([
        ['id' => 1, 'title' => 'Inbox'],
        ['id' => 5, 'title' => 'Nákupy'],
    ]);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer vik-token'));
});

it('returns empty labels when API returns null body', function () {
    Http::fake(['vikunja.test/api/v1/labels' => Http::response('null')]);

    expect((new VikunjaClient)->labels())->toBe([]);
});

it('creates a task via PUT and returns it', function () {
    Http::fake([
        'vikunja.test/api/v1/projects/5/tasks' => Http::response(['id' => 42, 'title' => 'Nakoupit mléko']),
    ]);

    $task = (new VikunjaClient)->createTask(5, ['title' => 'Nakoupit mléko']);

    expect($task['id'])->toBe(42);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/api/v1/projects/5/tasks')
        && $r->data()['title'] === 'Nakoupit mléko');
});

it('attaches a label via PUT', function () {
    Http::fake(['vikunja.test/api/v1/tasks/42/labels' => Http::response(['label_id' => 9])]);

    (new VikunjaClient)->attachLabel(42, 9);

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data()['label_id'] === 9);
});

it('throws on error responses', function () {
    Http::fake(['vikunja.test/*' => Http::response('', 503)]);

    (new VikunjaClient)->projects();
})->throws(RequestException::class);

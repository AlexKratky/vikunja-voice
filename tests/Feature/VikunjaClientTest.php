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

it('filters out pseudo-projects with negative ids', function () {
    Http::fake([
        'vikunja.test/api/v1/projects' => Http::response([
            ['id' => 1, 'title' => 'Úkoly', 'description' => ''],
            ['id' => -1, 'title' => 'Favorites', 'description' => ''],
            ['id' => -2, 'title' => 'My Open Tasks', 'description' => ''],
        ]),
    ]);

    expect((new VikunjaClient)->projects())->toBe([
        ['id' => 1, 'title' => 'Úkoly'],
    ]);
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

it('finds a user id by exact username among partial matches', function () {
    Http::fake([
        'vikunja.test/api/v1/users*' => Http::response([
            ['id' => 7, 'username' => 'alexander'],
            ['id' => 1, 'username' => 'alex'],
        ]),
    ]);

    expect((new VikunjaClient)->findUserId('alex'))->toBe(1);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/users') && $r->data()['s'] === 'alex');
});

it('returns null when no user matches', function () {
    Http::fake(['vikunja.test/api/v1/users*' => Http::response('null')]);

    expect((new VikunjaClient)->findUserId('ghost'))->toBeNull();
});

it('assigns a user via PUT', function () {
    Http::fake(['vikunja.test/api/v1/tasks/42/assignees' => Http::response(['user_id' => 1])]);

    (new VikunjaClient)->assignUser(42, 1);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/api/v1/tasks/42/assignees')
        && $r->data()['user_id'] === 1);
});

it('lists the newest open tasks across projects', function () {
    Http::fake([
        'vikunja.test/api/v1/tasks*' => Http::response([
            ['id' => 147, 'title' => 'Koupit olej', 'project_id' => 1, 'done' => false, 'due_date' => '2026-11-07T22:59:00Z'],
            ['id' => 145, 'title' => 'Vypnout NASku', 'project_id' => 2, 'done' => false, 'due_date' => '0001-01-01T00:00:00Z'],
        ]),
    ]);

    // Vikunja's zero time is "no due date".
    expect((new VikunjaClient)->openTasks())->toBe([
        ['id' => 147, 'title' => 'Koupit olej', 'project_id' => 1, 'due_date' => '2026-11-07T22:59:00Z'],
        ['id' => 145, 'title' => 'Vypnout NASku', 'project_id' => 2, 'due_date' => null],
    ]);

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), '/api/v1/tasks?')
        && $r->data() === ['filter' => 'done = false', 'sort_by' => 'created', 'order_by' => 'desc', 'per_page' => 50]);
});

it('searches open tasks by a term', function () {
    Http::fake(['vikunja.test/api/v1/tasks*' => Http::response('null')]);

    expect((new VikunjaClient)->openTasks('olej'))->toBe([]);

    Http::assertSent(fn ($r) => $r->data()['s'] === 'olej' && $r->data()['filter'] === 'done = false');
});

it('adds a comment via PUT', function () {
    Http::fake(['vikunja.test/api/v1/tasks/147/comments' => Http::response(['id' => 1])]);

    (new VikunjaClient)->addComment(147, '<p>Koupím v Hornbachu</p>');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && $r->data() === ['comment' => '<p>Koupím v Hornbachu</p>']);
});

it('updates a task by sending it back whole with the change applied', function () {
    $task = ['id' => 147, 'title' => 'Olej', 'description' => '<p>x</p>', 'done' => false, 'priority' => 2];
    Http::fake([
        'vikunja.test/api/v1/tasks/147' => fn ($r) => Http::response($r->method() === 'GET' ? $task : [...$r->data(), 'updated' => 'now']),
    ]);

    $updated = (new VikunjaClient)->updateTask(147, fn (array $current) => [...$current, 'done' => true]);

    expect($updated['done'])->toBeTrue();

    $requests = Http::recorded()->map(fn ($pair) => [$pair[0]->method(), $pair[0]->data()])->all();
    expect($requests)->toBe([
        ['GET', []],
        ['POST', [...$task, 'done' => true]],
    ]);
});

it('lists open tasks due by a time, soonest first, with only the conditions given', function () {
    Http::fake(['vikunja.test/api/v1/tasks*' => Http::response([])]);

    (new VikunjaClient)->dueTasks('2026-11-09T23:59:00+01:00', 4, 5, 'olej');
    (new VikunjaClient)->dueTasks();

    [$all, $none] = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
    expect($all)->toBe([
        'filter' => 'done = false && due_date <= 2026-11-09T23:59:00+01:00 && priority >= 4 && project = 5',
        'sort_by' => 'due_date',
        'order_by' => 'asc',
        'per_page' => 50,
        's' => 'olej',
    ])
        ->and($none['filter'])->toBe('done = false')
        ->and($none)->not->toHaveKey('s');
});

it('throws on error responses', function () {
    Http::fake(['vikunja.test/*' => Http::response('', 503)]);

    (new VikunjaClient)->projects();
})->throws(RequestException::class);

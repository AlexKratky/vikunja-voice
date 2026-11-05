<?php

use App\Services\ExtractionValidator;

function rawTask(array $overrides = []): array
{
    return array_merge([
        'transcript_segment' => null,
        'title' => 'Nakoupit mléko',
        'description' => 'Plnotučné',
        'due_date' => '2026-08-08T23:59:00+02:00',
        'priority' => 3,
        'project_id' => 5,
        'label_ids' => [1, 2],
    ], $overrides);
}

function validateRaw(array $raw): array
{
    return ExtractionValidator::validate(
        $raw,
        validProjectIds: [5, 7],
        validLabelIds: [1, 2, 9],
        defaultProjectId: 7,
        fallbackTitle: 'Recording 6. 8. 2026 14:32',
    );
}

/** Validates a single-task response and returns that task. */
function validate(array $taskOverrides = []): array
{
    return validateRaw([
        'transcript' => 'nakoupit mléko do pátku',
        'tasks' => [rawTask($taskOverrides)],
    ])['tasks'][0];
}

it('passes through valid data', function () {
    expect(validateRaw([
        'transcript' => 'nakoupit mléko do pátku',
        'tasks' => [rawTask(['transcript_segment' => 'nakoupit mléko'])],
    ]))->toBe([
        'transcript' => 'nakoupit mléko do pátku',
        'tasks' => [[
            'title' => 'Nakoupit mléko',
            'description' => 'Plnotučné',
            'due_date' => '2026-08-08T23:59:00+02:00',
            'priority' => 3,
            'project_id' => 5,
            'label_ids' => [1, 2],
            'transcript' => 'nakoupit mléko',
            'action' => 'create',
            'search_terms' => [],
            'reminders' => [],
        ]],
    ]);
});

it('sanitizes every task independently', function () {
    $result = validateRaw([
        'transcript' => 'dva úkoly',
        'tasks' => [
            rawTask(['title' => 'První', 'project_id' => 999, 'label_ids' => [1, 42]]),
            rawTask(['title' => 'Druhý', 'project_id' => 5, 'priority' => 9, 'label_ids' => [9]]),
        ],
    ]);

    expect($result['tasks'])->toHaveCount(2)
        ->and($result['tasks'][0]['title'])->toBe('První')
        ->and($result['tasks'][0]['project_id'])->toBe(7)
        ->and($result['tasks'][0]['label_ids'])->toBe([1])
        ->and($result['tasks'][1]['title'])->toBe('Druhý')
        ->and($result['tasks'][1]['project_id'])->toBe(5)
        ->and($result['tasks'][1]['priority'])->toBe(5)
        ->and($result['tasks'][1]['label_ids'])->toBe([9]);
});

it('builds a single task from the top-level keys when tasks are missing, empty or malformed', function (mixed $tasks) {
    $raw = [
        'transcript' => 'nakoupit mléko',
        'title' => 'Nakoupit mléko',
        'project_id' => 5,
        'label_ids' => [1],
    ];
    if ($tasks !== 'absent') {
        $raw['tasks'] = $tasks;
    }

    $result = validateRaw($raw);

    expect($result['transcript'])->toBe('nakoupit mléko')
        ->and($result['tasks'])->toHaveCount(1)
        ->and($result['tasks'][0]['title'])->toBe('Nakoupit mléko')
        ->and($result['tasks'][0]['project_id'])->toBe(5)
        ->and($result['tasks'][0]['label_ids'])->toBe([1])
        ->and($result['tasks'][0]['transcript'])->toBe('nakoupit mléko');
})->with([
    'absent' => ['absent'],
    'empty array' => [[]],
    'not an array' => ['nope'],
    'only garbage entries' => [['garbage', 12]],
]);

it('returns no tasks when the model reports an empty list', function () {
    expect(validateRaw(['transcript' => '', 'tasks' => []]))->toBe(['transcript' => '', 'tasks' => []])
        ->and(validateRaw(['transcript' => 'ehm', 'tasks' => [], 'title' => ' '])['tasks'])->toBe([]);
});

it('drops non-array entries from tasks', function () {
    $result = validateRaw([
        'transcript' => 'x',
        'tasks' => ['garbage', rawTask(['title' => 'Platný'])],
    ]);

    expect($result['tasks'])->toHaveCount(1)
        ->and($result['tasks'][0]['title'])->toBe('Platný');
});

it('prefers transcript_segment over transcript inside a task', function () {
    $task = validate(['transcript_segment' => 'segment', 'transcript' => 'whole']);
    expect($task['transcript'])->toBe('segment');

    $task = validate(['transcript_segment' => null, 'transcript' => 'whole']);
    expect($task['transcript'])->toBe('whole');
});

it('falls back to the given title when empty or missing', function () {
    expect(validate(['title' => '  '])['title'])->toBe('Recording 6. 8. 2026 14:32');
    expect(validate(['title' => null])['title'])->toBe('Recording 6. 8. 2026 14:32');
});

it('defaults description and transcripts to empty strings', function () {
    $result = validateRaw(['transcript' => null, 'tasks' => [rawTask(['description' => null])]]);

    expect($result['transcript'])->toBe('')
        ->and($result['tasks'][0]['description'])->toBe('')
        ->and($result['tasks'][0]['transcript'])->toBe('');
});

it('nulls unparseable due dates', function () {
    expect(validate(['due_date' => 'někdy příště'])['due_date'])->toBeNull();
    expect(validate(['due_date' => null])['due_date'])->toBeNull();
});

it('normalizes parseable due dates to RFC3339', function () {
    expect(validate(['due_date' => '2026-08-08 10:00:00'])['due_date'])
        ->toBe('2026-08-08T10:00:00+00:00');
});

it('clamps priority to 0-5 and defaults null to 0', function () {
    expect(validate(['priority' => 99])['priority'])->toBe(5);
    expect(validate(['priority' => -1])['priority'])->toBe(0);
    expect(validate(['priority' => null])['priority'])->toBe(0);
});

it('falls back to default project for unknown or missing project', function () {
    expect(validate(['project_id' => 999])['project_id'])->toBe(7);
    expect(validate(['project_id' => null])['project_id'])->toBe(7);
});

it('drops unknown label ids', function () {
    expect(validate(['label_ids' => [1, 42, 9]])['label_ids'])->toBe([1, 9]);
    expect(validate(['label_ids' => null])['label_ids'])->toBe([]);
});

it('keeps create_if_missing and treats any other action as create', function () {
    expect(validate(['action' => 'create_if_missing'])['action'])->toBe('create_if_missing');
    expect(validate(['action' => 'create'])['action'])->toBe('create');
    expect(validate(['action' => 'exists'])['action'])->toBe('create');
    expect(validate(['action' => null])['action'])->toBe('create');
    expect(validate()['action'])->toBe('create');
});

it('keeps up to five distinct search terms of at least three letters', function () {
    expect(validate(['search_terms' => [' olej ', 'Olej', 'stůl', 'stol', 'na', 12, null, 'stůl', 'lak', 'dřevo', 'xxxlutz']])['search_terms'])
        ->toBe(['olej', 'stůl', 'stol', 'lak', 'dřevo']);
    expect(validate(['search_terms' => 'olej'])['search_terms'])->toBe([]);
});

it('normalizes reminders to RFC3339 and drops unparseable or repeated ones', function () {
    expect(validate(['reminders' => ['2026-11-06T09:00:00+01:00', 'zítra ráno', '2026-11-06 09:00:00+01:00', null, '2026-11-07 08:00:00']])['reminders'])
        ->toBe(['2026-11-06T09:00:00+01:00', '2026-11-07T08:00:00+00:00']);
    expect(validate(['reminders' => '2026-11-06T09:00:00+01:00'])['reminders'])->toBe([]);
});

it('lets a list without a known project cover all projects', function () {
    expect(validate(['action' => 'list', 'project_id' => 999])['project_id'])->toBeNull();
    expect(validate(['action' => 'list', 'project_id' => null])['project_id'])->toBeNull();
    expect(validate(['action' => 'list', 'project_id' => 5])['project_id'])->toBe(5);
    expect(validate(['action' => 'create', 'project_id' => 999])['project_id'])->toBe(7);
});

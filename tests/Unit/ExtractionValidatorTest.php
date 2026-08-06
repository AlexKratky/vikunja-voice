<?php

use App\Services\ExtractionValidator;

function validate(array $overrides = []): array
{
    $raw = array_merge([
        'transcript' => 'nakoupit mléko do pátku',
        'title' => 'Nakoupit mléko',
        'description' => 'Plnotučné',
        'due_date' => '2026-08-08T23:59:00+02:00',
        'priority' => 3,
        'project_id' => 5,
        'label_ids' => [1, 2],
    ], $overrides);

    return ExtractionValidator::validate(
        $raw,
        validProjectIds: [5, 7],
        validLabelIds: [1, 2, 9],
        defaultProjectId: 7,
        now: new DateTimeImmutable('2026-08-06 14:32:00', new DateTimeZone('Europe/Prague')),
    );
}

it('passes through valid data', function () {
    expect(validate())->toBe([
        'title' => 'Nakoupit mléko',
        'description' => 'Plnotučné',
        'due_date' => '2026-08-08T23:59:00+02:00',
        'priority' => 3,
        'project_id' => 5,
        'label_ids' => [1, 2],
        'transcript' => 'nakoupit mléko do pátku',
    ]);
});

it('falls back to timestamped title when empty or missing', function () {
    expect(validate(['title' => '  '])['title'])->toBe('Nahrávka 6. 8. 2026 14:32');
    expect(validate(['title' => null])['title'])->toBe('Nahrávka 6. 8. 2026 14:32');
});

it('defaults description and transcript to empty strings', function () {
    $result = validate(['description' => null, 'transcript' => null]);
    expect($result['description'])->toBe('')->and($result['transcript'])->toBe('');
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

<?php

use App\Services\ExtractionValidator;
use App\Services\GeminiClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('voice.gemini.api_key', 'test-key');
    config()->set('voice.gemini.model', 'gemini-2.5-flash');
});

function geminiResponse(array $payload): array
{
    return [
        'candidates' => [
            ['content' => ['parts' => [['text' => json_encode($payload)]]]],
        ],
    ];
}

it('sends audio and context, returns decoded extraction', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'nakoupit mléko',
            'tasks' => [[
                'transcript_segment' => null,
                'title' => 'Nakoupit mléko',
                'description' => null,
                'due_date' => null,
                'priority' => null,
                'project_id' => 5,
                'label_ids' => [],
            ]],
        ])),
    ]);

    $result = (new GeminiClient)->extract(
        'FAKE-AUDIO-BYTES',
        'audio/webm',
        projects: [['id' => 5, 'title' => 'Nákupy']],
        labels: [['id' => 1, 'title' => 'doma']],
        now: new DateTimeImmutable('2026-08-06 14:32:00', new DateTimeZone('Europe/Prague')),
    );

    expect($result['transcript'])->toBe('nakoupit mléko')
        ->and($result['tasks'][0]['title'])->toBe('Nakoupit mléko')
        ->and($result['tasks'][0]['project_id'])->toBe(5);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $parts = $body['contents'][0]['parts'];

        return str_contains($request->url(), 'gemini-2.5-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && count($parts) === 1 // nothing beside the audio the model could transcribe
            && $parts[0]['inlineData']['data'] === base64_encode('FAKE-AUDIO-BYTES')
            && $parts[0]['inlineData']['mimeType'] === 'audio/webm'
            && str_contains($body['systemInstruction']['parts'][0]['text'], 'Nákupy')
            && str_contains($body['systemInstruction']['parts'][0]['text'], '2026-08-06')
            && $body['generationConfig']['responseMimeType'] === 'application/json';
    });
});

it('normalizes video/webm to audio/webm', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('FAKE-AUDIO-BYTES', 'video/webm', [], [], new DateTimeImmutable);

    Http::assertSent(fn ($request) => $request->data()['contents'][0]['parts'][0]['inlineData']['mimeType'] === 'audio/webm');
});

it('uses the English prompt by default', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($body['systemInstruction']['parts'][0]['text'], 'dictated in English')
            && count($body['contents'][0]['parts']) === 1;
    });
});

it('uses the Czech prompt when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($body['systemInstruction']['parts'][0]['text'], 'česky diktované hlasové poznámky')
            && count($body['contents'][0]['parts']) === 1;
    });
});

it('falls back to the English prompt for unsupported locales', function () {
    config()->set('voice.locale', 'de');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(fn ($request) => str_contains(
        $request->data()['systemInstruction']['parts'][0]['text'], 'dictated in English',
    ));
});

it('puts the configured timezone in the prompt', function () {
    config()->set('voice.timezone', 'America/New_York');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(fn ($request) => str_contains(
        $request->data()['systemInstruction']['parts'][0]['text'], 'America/New_York',
    ));
});

it('requests a whole transcript plus an ordered list of tasks that may be empty', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $schema = $request->data()['generationConfig']['responseSchema'];
        $task = $schema['properties']['tasks']['items'];

        return $schema['required'] === ['transcript', 'tasks']
            && $schema['propertyOrdering'] === ['transcript', 'tasks']
            && $schema['properties']['tasks']['type'] === 'ARRAY'
            && ! array_key_exists('minItems', $schema['properties']['tasks'])
            && $task['required'] === ['action', 'title', 'label_ids', 'search_terms', 'reminders']
            && $task['properties']['reminders'] === ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]
            && $task['propertyOrdering'][0] === 'transcript_segment'
            && $task['propertyOrdering'][1] === 'action'
            && $task['properties']['transcript_segment'] === ['type' => 'STRING', 'nullable' => true]
            && $task['properties']['action']['enum'] === ExtractionValidator::ACTIONS
            && $task['properties']['search_terms'] === ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
    });
});

it('instructs the English prompt to split only on an explicit cue and to clean the description', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];

        return str_contains($prompt, 'EXACTLY ONE task')
            && str_contains($prompt, '"new task"')
            && str_contains($prompt, 'drop filler words')
            && str_contains($prompt, '`transcript_segment`')
            && ! str_contains($prompt, 'always contains at least one task');
    });
});

it('instructs the Czech prompt to split only on an explicit cue and to clean the description', function () {
    config()->set('voice.locale', 'cs');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];

        return str_contains($prompt, 'PRÁVĚ JEDEN úkol')
            && str_contains($prompt, '„založ nový úkol"')
            && str_contains($prompt, 'vynech vatu')
            && str_contains($prompt, '`transcript_segment`')
            && ! str_contains($prompt, 'vždy alespoň jeden úkol');
    });
});

it('tells the model to return no task for silence instead of making one up', function (string $locale, array $phrases) {
    config()->set('voice.locale', $locale);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['transcript' => '', 'tasks' => []])),
    ]);

    $result = (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    expect($result)->toBe(['transcript' => '', 'tasks' => []]);

    Http::assertSent(function ($request) use ($phrases) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];

        foreach ($phrases as $phrase) {
            if (! str_contains($prompt, $phrase)) {
                return false;
            }
        }

        return true;
    });
})->with([
    'en' => ['en', ['never these instructions', 'an empty `tasks` array', 'Never make up a task', '`tasks` [].']],
    'cs' => ['cs', ['nikdy ne tyto instrukce', 'prázdné pole `tasks`', 'Nikdy si úkol nevymýšlej', '`tasks` [].']],
]);

it('explains the create-if-missing command and its search terms', function (string $locale, array $phrases) {
    config()->set('voice.locale', $locale);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['transcript' => '', 'tasks' => []])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) use ($phrases) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];

        foreach ([...$phrases, '`action`', '`search_terms`', 'create_if_missing'] as $phrase) {
            if (! str_contains($prompt, $phrase)) {
                return false;
            }
        }

        return true;
    });
})->with([
    'en' => ['en', ['"create a task if it doesn\'t exist"', 'cut down to its stem']],
    'cs' => ['cs', ['„založ úkol, pokud neexistuje"', 'zkrácené na kořen']],
]);

it('leaves a switched-off command out of the prompt and the schema', function (string $locale) {
    config()->set('voice.locale', $locale);
    config()->set('voice.commands.create_if_missing', false);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['transcript' => '', 'tasks' => []])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];
        $task = $request->data()['generationConfig']['responseSchema']['properties']['tasks']['items'];

        return ! str_contains($prompt, 'create_if_missing')
            && str_contains($prompt, '`action`')
            && ! in_array('create_if_missing', $task['properties']['action']['enum'], true)
            && in_array('comment', $task['properties']['action']['enum'], true);
    });
})->with(['en', 'cs']);

it('asks for reminders only while they are switched on', function (string $locale, string $rule, string $onUpdate) {
    config()->set('voice.locale', $locale);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['transcript' => '', 'tasks' => []])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
    config()->set('voice.commands.reminders', false);
    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    [$on, $off] = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
    $schema = fn (array $body) => $body['generationConfig']['responseSchema']['properties']['tasks']['items'];
    $prompt = fn (array $body) => $body['systemInstruction']['parts'][0]['text'];

    expect($prompt($on))->toContain('- `reminders`:')->toContain($rule)->toContain($onUpdate)
        ->and($schema($on)['properties'])->toHaveKey('reminders')
        ->and($prompt($off))->not->toContain('`reminders`')->not->toContain($onUpdate)
        ->and($schema($off)['properties'])->not->toHaveKey('reminders')
        ->and($schema($off)['propertyOrdering'])->not->toContain('reminders')
        ->and($schema($off)['properties'])->toHaveKey('action');
})->with([
    'en' => ['en', '"remind me tomorrow at 9"', 'or add a reminder to it'],
    'cs' => ['cs', '„připomeň mi zítra v 9"', 'nebo k němu přidej připomenutí'],
]);

it('asks for the plain task schema with every command switched off', function (string $locale) {
    config()->set('voice.locale', $locale);
    config()->set('voice.commands', array_map(fn () => false, config('voice.commands')));
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['transcript' => '', 'tasks' => []])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $prompt = $request->data()['systemInstruction']['parts'][0]['text'];
        $task = $request->data()['generationConfig']['responseSchema']['properties']['tasks']['items'];

        return ! str_contains($prompt, 'create_if_missing')
            && ! str_contains($prompt, '`action`')
            && ! str_contains($prompt, '`search_terms`')
            && ! array_key_exists('action', $task['properties'])
            && ! array_key_exists('search_terms', $task['properties'])
            && $task['required'] === ['title', 'label_ids']
            && ! in_array('action', $task['propertyOrdering'], true);
    });
})->with(['en', 'cs']);

it('asks which open task each item is about with a text-only request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'decisions' => [['task' => 1, 'reason' => 'Same purchase', 'existing_task_id' => 147]],
        ])),
    ]);

    $result = (new GeminiClient)->matchExisting(
        [
            ['kind' => 'new', 'title' => 'Koupit olej na stůl', 'description' => ''],
            ['kind' => 'existing', 'title' => 'Doktor', 'description' => 'Objednat na úterý'],
        ],
        [
            ['id' => 147, 'title' => 'Koupit olej na olejování stolu', 'project' => 'Inbox'],
            ['id' => 145, 'title' => 'Vypnout NASku', 'project' => 'Domov'],
        ],
    );

    expect($result['decisions'][0]['existing_task_id'])->toBe(147);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $schema = $body['generationConfig']['responseSchema'];
        $decision = $schema['properties']['decisions']['items'];

        return $body['contents'][0]['parts'] === [['text' => "Items:\n"
            ."1. [new task] Koupit olej na stůl\n"
            ."2. [existing task] Doktor — Objednat na úterý\n\n"
            ."Open tasks:\n"
            ."- 147: Koupit olej na olejování stolu [Inbox]\n"
            .'- 145: Vypnout NASku [Domov]']]
            && str_contains($body['systemInstruction']['parts'][0]['text'], 'Never return an id that is not in the list')
            && $decision['propertyOrdering'] === ['task', 'reason', 'existing_task_id']
            && $decision['properties']['existing_task_id'] === ['type' => 'INTEGER', 'nullable' => true];
    });
});

it('throws on HTTP error', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500)]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
})->throws(RuntimeException::class);

it('includes the response body in HTTP error messages', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 400, 'message' => 'The video is corrupted or has wrong video metadata. 0 Frames found.'],
        ], 400),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
})->throws(RuntimeException::class, 'The video is corrupted');

it('throws on unparseable response', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'not json {']]]]],
        ]),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
})->throws(RuntimeException::class);

it('sends a typed note as text instead of audio', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'koupit mléko', 'tasks' => [['title' => 'Koupit mléko', 'label_ids' => []]],
        ])),
    ]);

    $result = (new GeminiClient)->extractFromText(
        'koupit mléko',
        projects: [['id' => 5, 'title' => 'Nákupy']],
        labels: [],
        now: new DateTimeImmutable('2026-08-06 14:32:00', new DateTimeZone('Europe/Prague')),
    );

    expect($result['tasks'][0]['title'])->toBe('Koupit mléko');

    Http::assertSent(function ($request) {
        $body = $request->data();
        $parts = $body['contents'][0]['parts'];
        $prompt = $body['systemInstruction']['parts'][0]['text'];

        return $parts === [['text' => 'koupit mléko']]
            && str_contains($prompt, 'typed note')
            && str_contains($prompt, 'Nákupy')
            && str_contains($prompt, '2026-08-06')
            && $body['generationConfig']['responseSchema']['required'] === ['transcript', 'tasks'];
    });
});

it('explains a typed note in the Czech prompt when locale is cs', function () {
    config()->set('voice.locale', 'cs');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extractFromText('x', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($body['systemInstruction']['parts'][0]['text'], 'napsanou poznámku')
            && $body['contents'][0]['parts'] === [['text' => 'x']];
    });
});

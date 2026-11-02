<?php

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

it('uses the English prompt and user instruction by default', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
            'transcript' => 'x', 'tasks' => [['title' => 'x', 'label_ids' => []]],
        ])),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($body['systemInstruction']['parts'][0]['text'], 'dictated in English')
            && str_contains($body['contents'][0]['parts'][1]['text'], 'Process this voice note');
    });
});

it('uses the Czech prompt and user instruction when locale is cs', function () {
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
            && str_contains($body['contents'][0]['parts'][1]['text'], 'Zpracuj tuto hlasovou poznámku');
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

it('requests a whole transcript plus a non-empty, ordered list of tasks', function () {
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
            && $schema['properties']['tasks']['minItems'] === 1
            && $task['required'] === ['title', 'label_ids']
            && $task['propertyOrdering'][0] === 'transcript_segment'
            && $task['properties']['transcript_segment'] === ['type' => 'STRING', 'nullable' => true];
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
            && str_contains($prompt, '`transcript_segment`');
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
            && str_contains($prompt, '`transcript_segment`');
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

        return $parts[0] === ['text' => 'koupit mléko']
            && str_contains($parts[1]['text'], 'Process this typed note')
            && str_contains($prompt, 'typed note')
            && str_contains($prompt, 'Nákupy')
            && str_contains($prompt, '2026-08-06')
            && $body['generationConfig']['responseSchema']['required'] === ['transcript', 'tasks'];
    });
});

it('uses the Czech instruction for a typed note when locale is cs', function () {
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
            && str_contains($body['contents'][0]['parts'][1]['text'], 'Zpracuj tuto napsanou poznámku');
    });
});

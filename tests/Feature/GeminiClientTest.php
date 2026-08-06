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
            'title' => 'Nakoupit mléko',
            'description' => null,
            'due_date' => null,
            'priority' => null,
            'project_id' => 5,
            'label_ids' => [],
        ])),
    ]);

    $result = (new GeminiClient)->extract(
        'FAKE-AUDIO-BYTES',
        'audio/webm',
        projects: [['id' => 5, 'title' => 'Nákupy']],
        labels: [['id' => 1, 'title' => 'doma']],
        now: new DateTimeImmutable('2026-08-06 14:32:00', new DateTimeZone('Europe/Prague')),
    );

    expect($result['title'])->toBe('Nakoupit mléko')->and($result['project_id'])->toBe(5);

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

it('throws on HTTP error', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500)]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
})->throws(RuntimeException::class);

it('throws on unparseable response', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'not json {']]]]],
        ]),
    ]);

    (new GeminiClient)->extract('x', 'audio/webm', [], [], new DateTimeImmutable);
})->throws(RuntimeException::class);

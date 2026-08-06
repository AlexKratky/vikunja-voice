<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiClient
{
    public function extract(
        string $audioContents,
        string $mimeType,
        array $projects,
        array $labels,
        \DateTimeImmutable $now,
    ): array {
        $model = config('voice.gemini.model');

        $response = Http::withHeaders(['x-goog-api-key' => config('voice.gemini.api_key')])
            ->timeout(120)
            ->asJson()
            ->post("https://generativelanguage.googleapis.com/v1beta/models/".$model.":generateContent", [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt($projects, $labels, $now)]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($audioContents)]],
                        ['text' => 'Zpracuj tuto hlasovou poznámku podle instrukcí.'],
                    ],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::RESPONSE_SCHEMA,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Gemini API error: HTTP {$response->status()}");
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded)) {
            throw new \RuntimeException('Gemini returned unparseable extraction: '.substr((string) $text, 0, 200));
        }

        return $decoded;
    }

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'transcript' => ['type' => 'STRING'],
            'title' => ['type' => 'STRING'],
            'description' => ['type' => 'STRING', 'nullable' => true],
            'due_date' => ['type' => 'STRING', 'nullable' => true],
            'priority' => ['type' => 'INTEGER', 'nullable' => true],
            'project_id' => ['type' => 'INTEGER', 'nullable' => true],
            'label_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
        ],
        'required' => ['transcript', 'title', 'label_ids'],
    ];

    private function systemPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        $projectList = implode("\n", array_map(
            fn ($p) => "- {$p['id']}: {$p['title']}", $projects,
        )) ?: '- (žádné projekty)';

        $labelList = implode("\n", array_map(
            fn ($l) => "- {$l['id']}: {$l['title']}", $labels,
        )) ?: '- (žádné štítky)';

        $nowText = $now->format('Y-m-d, l, j. n. Y H:i (P)');

        return <<<PROMPT
Jsi asistent, který převádí česky diktované hlasové poznámky na úkoly pro aplikaci Vikunja.

Dostaneš audio nahrávku. Tvým úkolem je:
1. Doslovně ji přepsat do pole `transcript` (česky, bez úprav obsahu).
2. Vytěžit z ní úkol do ostatních polí.

Aktuální datum a čas: {$nowText}, časová zóna Europe/Prague.

Dostupné projekty (id: název):
{$projectList}

Dostupné štítky (id: název):
{$labelList}

Pravidla:
- `title`: krátký výstižný název úkolu (ne celý přepis).
- `description`: doplňující detaily z nahrávky; pokud žádné nejsou, null.
- `due_date`: termín ve formátu RFC3339 s časovou zónou (např. 2026-08-08T23:59:00+02:00). Relativní výrazy („zítra", „do pátku", „příští týden") přepočti podle aktuálního data. Pokud mluvčí nezmíní čas, použij 23:59. Pokud termín nezazní, null.
- `priority`: 0–5 podle Vikunja škály — 1 nízká, 2 střední, 3 vysoká, 4 urgentní, 5 „okamžitě/ASAP". Pokud priorita nezazní, null.
- `project_id`: id projektu ze seznamu, který nejlépe odpovídá zmínce v nahrávce (názvy mohou být zkomolené nebo skloňované). Pokud žádný projekt nezazní nebo si nejsi jistý, null.
- `label_ids`: id štítků ze seznamu, které mluvčí zmíní. Nikdy nevymýšlej id mimo seznam. Pokud žádné, prázdné pole.

Příklady:
- „Hoď mi do retechu opravit deploy skript, do pátku, je to urgentní" → title „Opravit deploy skript", project_id = id projektu RETech (pokud existuje), due_date = nejbližší pátek 23:59, priority = 4.
- „Zítra ráno zavolat doktorovi kvůli objednání" → title „Zavolat doktorovi", description „Kvůli objednání", due_date = zítra 09:00, priority = null.
- „Někdy koupit nový kabel k notebooku" → title „Koupit nový kabel k notebooku", due_date = null, priority = null, project_id = null.
PROMPT;
    }
}

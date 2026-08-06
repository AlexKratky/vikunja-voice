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

        // Browsers record audio-only WebM that gets sniffed as video/webm;
        // Gemini then routes it to its video pipeline, which fails on a file
        // with zero video frames ("The video is corrupted", HTTP 400).
        if ($mimeType === 'video/webm') {
            $mimeType = 'audio/webm';
        }

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
                        ['text' => $this->userInstruction()],
                    ],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::RESPONSE_SCHEMA,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Gemini API error: HTTP {$response->status()}: ".substr($response->body(), 0, 500),
            );
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

    private function userInstruction(): string
    {
        return match (config('voice.locale')) {
            'cs' => 'Zpracuj tuto hlasovou poznámku podle instrukcí.',
            default => 'Process this voice note according to the instructions.',
        };
    }

    private function systemPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        return match (config('voice.locale')) {
            'cs' => $this->czechPrompt($projects, $labels, $now),
            default => $this->englishPrompt($projects, $labels, $now),
        };
    }

    private function formatList(array $items, string $emptyText): string
    {
        return implode("\n", array_map(
            fn ($i) => "- {$i['id']}: {$i['title']}", $items,
        )) ?: "- ({$emptyText})";
    }

    private function englishPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        $projectList = $this->formatList($projects, 'no projects');
        $labelList = $this->formatList($labels, 'no labels');
        $nowText = $now->format('Y-m-d, l, j. n. Y H:i (P)');
        $timezone = config('voice.timezone');

        return <<<PROMPT
You are an assistant that turns voice notes dictated in English into tasks for the Vikunja app.

You will receive an audio recording. Your job is to:
1. Transcribe it verbatim into the `transcript` field (in English, without altering the content).
2. Extract a task from it into the remaining fields.

Current date and time: {$nowText}, timezone {$timezone}.

Available projects (id: name):
{$projectList}

Available labels (id: name):
{$labelList}

Rules:
- `title`: a short, descriptive task name (not the whole transcript).
- `description`: additional details from the recording; null if there are none.
- `due_date`: deadline in RFC3339 format with timezone offset (e.g. 2026-08-08T23:59:00+02:00). Resolve relative expressions ("tomorrow", "by Friday", "next week") against the current date. If the speaker doesn't mention a time of day, use 23:59. If no deadline is mentioned, null.
- `priority`: 0–5 on the Vikunja scale — 1 low, 2 medium, 3 high, 4 urgent, 5 "do it now/ASAP". If no priority is mentioned, null.
- `project_id`: the id of the project from the list that best matches a mention in the recording (names may be mispronounced or approximate). If no project is mentioned or you are unsure, null.
- `label_ids`: ids of labels from the list that the speaker mentions. Never invent ids outside the list. If none, an empty array.

Examples:
- "Add fixing the deploy script to RETech, by Friday, it's urgent" → title "Fix deploy script", project_id = id of the RETech project (if it exists), due_date = nearest Friday 23:59, priority = 4.
- "Call the doctor tomorrow morning about the appointment" → title "Call the doctor", description "About the appointment", due_date = tomorrow 09:00, priority = null.
- "Sometime buy a new laptop cable" → title "Buy a new laptop cable", due_date = null, priority = null, project_id = null.
PROMPT;
    }

    private function czechPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        $projectList = $this->formatList($projects, 'žádné projekty');
        $labelList = $this->formatList($labels, 'žádné štítky');
        $nowText = $now->format('Y-m-d, l, j. n. Y H:i (P)');
        $timezone = config('voice.timezone');

        return <<<PROMPT
Jsi asistent, který převádí česky diktované hlasové poznámky na úkoly pro aplikaci Vikunja.

Dostaneš audio nahrávku. Tvým úkolem je:
1. Doslovně ji přepsat do pole `transcript` (česky, bez úprav obsahu).
2. Vytěžit z ní úkol do ostatních polí.

Aktuální datum a čas: {$nowText}, časová zóna {$timezone}.

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

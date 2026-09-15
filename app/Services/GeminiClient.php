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
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
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

    // Gemini generates properties in `propertyOrdering` order (alphabetical
    // otherwise): the verbatim segment comes first so every derived field is
    // grounded in the text the model has just quoted.
    private const TASK_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'transcript_segment' => ['type' => 'STRING', 'nullable' => true],
            'title' => ['type' => 'STRING'],
            'description' => ['type' => 'STRING', 'nullable' => true],
            'due_date' => ['type' => 'STRING', 'nullable' => true],
            'priority' => ['type' => 'INTEGER', 'nullable' => true],
            'project_id' => ['type' => 'INTEGER', 'nullable' => true],
            'label_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
        ],
        'required' => ['title', 'label_ids'],
        'propertyOrdering' => ['transcript_segment', 'title', 'description', 'due_date', 'priority', 'project_id', 'label_ids'],
    ];

    // Whole transcript first, tasks derived from it second.
    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'transcript' => ['type' => 'STRING'],
            'tasks' => ['type' => 'ARRAY', 'minItems' => 1, 'items' => self::TASK_SCHEMA],
        ],
        'required' => ['transcript', 'tasks'],
        'propertyOrdering' => ['transcript', 'tasks'],
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
1. Transcribe it verbatim into the top-level `transcript` field (in English, without altering the content).
2. Extract one or more tasks from it into the `tasks` array.

Current date and time: {$nowText}, timezone {$timezone}.

Available projects (id: name):
{$projectList}

Available labels (id: name):
{$labelList}

How many tasks:
- By default the whole recording is EXACTLY ONE task. A change of topic, an unrelated subject or a new sentence is NOT a reason to split.
- Start a new task only when the speaker explicitly asks for one: "new task", "next task", "another task", "create another task", "second task", "one more task", or an obvious variant.
- The cue phrase itself belongs to no task — it must not appear in any `title`, `description` or `transcript_segment` (it stays only in the verbatim `transcript`).
- `tasks` always contains at least one task.

Rules for each task:
- `title`: a short, descriptive task name (not the whole transcript).
- `description`: a concise, cleaned-up version of what the speaker said about this task — drop filler words, hesitations, false starts and repetitions, and drop anything already captured in another field (deadline or time, priority, project, labels). If nothing is left, null.
- `due_date`: deadline in RFC3339 format with timezone offset (e.g. 2026-08-08T23:59:00+02:00). Resolve relative expressions ("tomorrow", "by Friday", "next week") against the current date. If the speaker doesn't mention a time of day, use 23:59. If no deadline is mentioned, null.
- `priority`: 0–5 on the Vikunja scale — 1 low, 2 medium, 3 high, 4 urgent, 5 "do it now/ASAP". If no priority is mentioned, null.
- `project_id`: the id of the project from the list that best matches a mention in the recording (names may be mispronounced or approximate). If no project is mentioned or you are unsure, null.
- `label_ids`: ids of labels from the list that the speaker mentions. Never invent ids outside the list. If none, an empty array.
- `transcript_segment`: the verbatim part of the recording this task comes from, without the cue phrase. With a single task leave it null — the whole transcript is used.

Examples:
- "Add fixing the deploy script to RETech, by Friday, it's urgent" → one task: title "Fix deploy script", project_id = id of the RETech project (if it exists), due_date = nearest Friday 23:59, priority = 4, description = null (everything is already in the other fields).
- "Call the doctor tomorrow morning about, uh, about the appointment" → one task: title "Call the doctor", description "About the appointment", due_date = tomorrow 09:00, priority = null.
- "Sometime buy a new laptop cable" → one task: title "Buy a new laptop cable", description = null, due_date = null, priority = null, project_id = null.
- "Buy milk and bread, today ideally. New task. Call the doctor about the appointment, it's urgent" → two tasks, because the speaker said "new task": 1) title "Buy milk and bread", due_date = today 23:59, transcript_segment "Buy milk and bread, today ideally"; 2) title "Call the doctor", description "About the appointment", priority = 4, transcript_segment "Call the doctor about the appointment, it's urgent".
- "Buy milk and bread and also call the doctor about the appointment" → ONE task (no cue phrase was said), e.g. title "Buy milk and bread and call the doctor".
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
2. Vytěžit z ní jeden nebo více úkolů do pole `tasks`.

Aktuální datum a čas: {$nowText}, časová zóna {$timezone}.

Dostupné projekty (id: název):
{$projectList}

Dostupné štítky (id: název):
{$labelList}

Kolik úkolů:
- Ve výchozím stavu je celá nahrávka PRÁVĚ JEDEN úkol. Změna tématu, nesouvisející věc ani nová věta NENÍ důvod k rozdělení.
- Nový úkol začni jen tehdy, když si o to mluvčí výslovně řekne: „založ nový úkol", „další úkol", „nový úkol", „ještě jeden úkol", „druhý úkol", nebo zjevná varianta.
- Samotná tato fráze do žádného úkolu nepatří — nesmí se objevit v `title`, `description` ani `transcript_segment` (zůstává jen v doslovném `transcript`).
- Pole `tasks` obsahuje vždy alespoň jeden úkol.

Pravidla pro každý úkol:
- `title`: krátký výstižný název úkolu (ne celý přepis).
- `description`: stručný, vyčištěný obsah toho, co k úkolu zaznělo — vynech vatu, zaváhání, opravy a opakování a vynech i to, co už je zachyceno v jiném poli (termín nebo čas, priorita, projekt, štítky). Pokud nic nezbude, null.
- `due_date`: termín ve formátu RFC3339 s časovou zónou (např. 2026-08-08T23:59:00+02:00). Relativní výrazy („zítra", „do pátku", „příští týden") přepočti podle aktuálního data. Pokud mluvčí nezmíní čas, použij 23:59. Pokud termín nezazní, null.
- `priority`: 0–5 podle Vikunja škály — 1 nízká, 2 střední, 3 vysoká, 4 urgentní, 5 „okamžitě/ASAP". Pokud priorita nezazní, null.
- `project_id`: id projektu ze seznamu, který nejlépe odpovídá zmínce v nahrávce (názvy mohou být zkomolené nebo skloňované). Pokud žádný projekt nezazní nebo si nejsi jistý, null.
- `label_ids`: id štítků ze seznamu, které mluvčí zmíní. Nikdy nevymýšlej id mimo seznam. Pokud žádné, prázdné pole.
- `transcript_segment`: doslovná část nahrávky, ze které tento úkol vznikl, bez fráze o novém úkolu. Pokud je úkol jen jeden, nech null — použije se celý přepis.

Příklady:
- „Hoď mi do retechu opravit deploy skript, do pátku, je to urgentní" → jeden úkol: title „Opravit deploy skript", project_id = id projektu RETech (pokud existuje), due_date = nejbližší pátek 23:59, priority = 4, description = null (vše už je v ostatních polích).
- „Zítra ráno zavolat doktorovi kvůli, ehm, kvůli objednání" → jeden úkol: title „Zavolat doktorovi", description „Kvůli objednání", due_date = zítra 09:00, priority = null.
- „Někdy koupit nový kabel k notebooku" → jeden úkol: title „Koupit nový kabel k notebooku", description = null, due_date = null, priority = null, project_id = null.
- „Koupit mléko a chleba, ideálně dnes. Založ další úkol. Zavolat doktorovi kvůli objednání, je to urgentní" → dva úkoly, protože zaznělo „založ další úkol": 1) title „Koupit mléko a chleba", due_date = dnes 23:59, transcript_segment „Koupit mléko a chleba, ideálně dnes"; 2) title „Zavolat doktorovi", description „Kvůli objednání", priority = 4, transcript_segment „Zavolat doktorovi kvůli objednání, je to urgentní".
- „Koupit mléko a chleba a taky zavolat doktorovi kvůli objednání" → JEDEN úkol (žádná fráze o novém úkolu nezazněla), např. title „Koupit mléko a chleba a zavolat doktorovi".
PROMPT;
    }
}

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
        // Browsers record audio-only WebM that gets sniffed as video/webm;
        // Gemini then routes it to its video pipeline, which fails on a file
        // with zero video frames ("The video is corrupted", HTTP 400).
        if ($mimeType === 'video/webm') {
            $mimeType = 'audio/webm';
        }

        return $this->generate(
            ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($audioContents)]],
            $projects,
            $labels,
            $now,
        );
    }

    /**
     * Same extraction for a typed note: the text stands in for the audio and
     * is its own verbatim transcript.
     */
    public function extractFromText(
        string $text,
        array $projects,
        array $labels,
        \DateTimeImmutable $now,
    ): array {
        return $this->generate(['text' => $text], $projects, $labels, $now);
    }

    /**
     * Decide which open task each item is about: for a `new` one the same
     * task, for an `existing` one the task the speaker named. Text only, and
     * one English prompt serves every locale: the input is the model's own
     * extraction and only the ids it returns are acted on.
     *
     * @param  list<array{kind: 'new'|'existing', title: string, description: string}>  $items
     * @param  list<array{id: int, title: string, project: string}>  $openTasks
     */
    public function matchExisting(array $items, array $openTasks): array
    {
        $itemList = implode("\n", array_map(
            fn ($item, $i) => ($i + 1).". [{$item['kind']} task] {$item['title']}"
                .($item['description'] !== '' ? " — {$item['description']}" : ''),
            $items,
            array_keys($items),
        ));
        $openList = implode("\n", array_map(
            fn ($task) => "- {$task['id']}: {$task['title']} [{$task['project']}]",
            $openTasks,
        ));

        return $this->request(
            self::MATCH_PROMPT,
            ['text' => "Items:\n{$itemList}\n\nOpen tasks:\n{$openList}"],
            self::MATCH_SCHEMA,
        );
    }

    private function generate(
        array $input,
        array $projects,
        array $labels,
        \DateTimeImmutable $now,
    ): array {
        return $this->request($this->systemPrompt($projects, $labels, $now), $input, $this->responseSchema());
    }

    private function request(string $systemPrompt, array $input, array $schema): array
    {
        $model = config('voice.gemini.model');

        $response = Http::withHeaders(['x-goog-api-key' => config('voice.gemini.api_key')])
            ->timeout(120)
            ->asJson()
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                // The input is the whole user turn: a "process this note"
                // text part beside it got transcribed as if it were spoken,
                // and on a silent recording it became the task.
                'contents' => [[
                    'role' => 'user',
                    'parts' => [$input],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
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
            throw new \RuntimeException('Gemini returned unparseable JSON: '.substr((string) $text, 0, 200));
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
            'action' => ['type' => 'STRING', 'format' => 'enum', 'enum' => ExtractionValidator::ACTIONS],
            'title' => ['type' => 'STRING'],
            'description' => ['type' => 'STRING', 'nullable' => true],
            'due_date' => ['type' => 'STRING', 'nullable' => true],
            'priority' => ['type' => 'INTEGER', 'nullable' => true],
            'project_id' => ['type' => 'INTEGER', 'nullable' => true],
            'label_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
            'search_terms' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ],
        'required' => ['action', 'title', 'label_ids', 'search_terms'],
        'propertyOrdering' => ['transcript_segment', 'action', 'title', 'description', 'due_date', 'priority', 'project_id', 'label_ids', 'search_terms'],
    ];

    // Whole transcript first, tasks derived from it second. `tasks` may be
    // empty: forcing at least one made the model invent a task from silence.
    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'transcript' => ['type' => 'STRING'],
                'tasks' => ['type' => 'ARRAY', 'items' => $this->taskSchema()],
            ],
            'required' => ['transcript', 'tasks'],
            'propertyOrdering' => ['transcript', 'tasks'],
        ];
    }

    /**
     * With every command switched off the model sees neither `action` nor
     * `search_terms`: the schema is the plain task one.
     */
    private function taskSchema(): array
    {
        $schema = self::TASK_SCHEMA;
        $schema['properties']['action']['enum'] = $this->actions();
        $off = $this->actions() === ['create'] ? ['action', 'search_terms'] : [];

        foreach ($off as $key) {
            unset($schema['properties'][$key]);
        }
        $schema['required'] = array_values(array_diff($schema['required'], $off));
        $schema['propertyOrdering'] = array_values(array_diff($schema['propertyOrdering'], $off));

        return $schema;
    }

    /**
     * What the model may do with an item: `create` always, every other action
     * only while its command is switched on (`voice.commands`).
     *
     * @return list<string>
     */
    private function actions(): array
    {
        return array_values(array_filter(
            ExtractionValidator::ACTIONS,
            fn (string $action) => $action === 'create' || (bool) config("voice.commands.{$action}"),
        ));
    }

    /**
     * The `action` and `search_terms` rules plus examples for the switched-on
     * commands only, so a switched-off command leaves no trace in the prompt
     * and its phrase gets dictated as an ordinary task.
     *
     * @param  array{intro: string, actions: array<string, string>, outro: string, search_terms: string, examples: array<string, string>}  $text
     * @param  \Closure(string): string  $quote
     * @return array{0: string, 1: string} rules, examples — each empty or starting with a newline
     */
    private function commandPrompt(array $text, \Closure $quote): array
    {
        $actions = $this->actions();

        if ($actions === ['create']) {
            return ['', ''];
        }

        $searched = array_values(array_intersect(ExtractionValidator::LOOKUP_ACTIONS, $actions));
        $rules = [
            $text['intro'],
            ...array_map(fn ($action) => '  - '.$quote($action).': '.$text['actions'][$action], $actions),
            '  '.$text['outro'],
            str_replace(':actions', implode(', ', array_map($quote, $searched)), $text['search_terms']),
        ];
        $examples = array_map(fn ($action) => $text['examples'][$action], array_slice($actions, 1));

        return ["\n".implode("\n", $rules), "\n".implode("\n", $examples)];
    }

    // The reason comes before the id so the decision follows from it.
    private const MATCH_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'decisions' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'task' => ['type' => 'INTEGER'],
                        'reason' => ['type' => 'STRING'],
                        'existing_task_id' => ['type' => 'INTEGER', 'nullable' => true],
                    ],
                    'required' => ['task', 'reason', 'existing_task_id'],
                    'propertyOrdering' => ['task', 'reason', 'existing_task_id'],
                ],
            ],
        ],
        'required' => ['decisions'],
    ];

    private const MATCH_PROMPT = <<<'PROMPT'
You find which of the user's open tasks in the Vikunja app the items they have just dictated are about.

The user message lists the items (numbered: [kind] title — description) and the user's open tasks (id: title [project]). Return one decision for every item:
- `task`: the number of the item.
- `reason`: one short sentence explaining the decision.
- `existing_task_id`: the id of the open task the item is about, or null.
  - [new task]: the user wants this task created unless it already exists. Return the open task that is the same piece of work — the same action on the same thing, even when it is worded differently, inflected, abbreviated, more or less detailed, in another language or in another project. A task that only shares the topic or the object but means a different action, or is a follow-up step, is NOT the same. If no open task is the same, or you are unsure, null: a duplicate task is a much smaller harm than a task that never gets created.
  - [existing task]: the user refers to one of their tasks by this name, to act on it. Return the open task they mean; the name may be shortened, inflected, misheard or paraphrased. If no open task fits, or several fit equally well, null: acting on the wrong task is worse than not acting.
Never return an id that is not in the list.
PROMPT;

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

    private function englishCommands(): array
    {
        return [
            'intro' => '- `action`: what to do with this item. Besides dictating tasks, the speaker can give these commands:',
            'actions' => [
                'create' => 'a new task — the default for everything that is not one of the commands below.',
                'create_if_missing' => 'create the task only if it does not exist yet ("create a task if it doesn\'t exist", "only if it doesn\'t exist yet", "unless it already exists", "if I don\'t have it yet", or an obvious variant). The fields are those of a new task. The phrase belongs to the task it is said with; said once for all tasks (e.g. "create these if they don\'t exist yet"), it applies to all of them.',
                'comment' => 'add a note to an existing task ("add to the task … that …", "a note for the … task", or an obvious variant). `title`: the existing task as the speaker named it; `description`: the note itself, cleaned up like a description.',
            ],
            'outro' => 'The command phrase itself belongs to no field, just like the "new task" cue. A different command starts a new item even without the "new task" cue, and a command is an item even when no task gets created. A task that merely mentions another task is not a command.',
            'search_terms' => '- `search_terms`: with :actions — 2 to 5 keywords to find the existing task by: the distinctive words of the task, each a single word cut down to its stem so other forms match too, plus an obvious synonym (e.g. "invoices" → "invoice"; "the dentist\'s" → "dentist"). Each term is searched separately as a substring of task titles and descriptions. With "create" an empty array.',
            'examples' => [
                'create_if_missing' => '- "Create a task if it doesn\'t exist: renew the car insurance" → ONE item: action "create_if_missing", title "Renew car insurance", search_terms ["insurance", "car", "renew"], description = null.',
                'comment' => '- "Add to the deploy script task that it only fails on staging" → action "comment", title "Deploy script", description "Only fails on staging", search_terms ["deploy", "script"].',
            ],
        ];
    }

    private function czechCommands(): array
    {
        return [
            'intro' => '- `action`: co s položkou udělat. Kromě diktování úkolů může mluvčí dávat tyto příkazy:',
            'actions' => [
                'create' => 'nový úkol — výchozí pro vše, co není žádný z příkazů níže.',
                'create_if_missing' => 'založ úkol jen pokud ještě neexistuje („založ úkol, pokud neexistuje", „pokud ještě neexistuje", „jestli ho tam ještě nemám", „pokud už tam není", nebo zjevná varianta). Pole jsou jako u nového úkolu. Fráze patří k úkolu, u kterého zazní; řekne-li ji mluvčí jednou pro všechny úkoly (např. „založ tyhle úkoly, pokud neexistujou"), platí pro všechny.',
                'comment' => 'doplň poznámku k existujícímu úkolu („doplň k úkolu …, že …", „k úkolu … přidej …", „poznámka k úkolu …", nebo zjevná varianta). `title`: existující úkol tak, jak ho mluvčí pojmenoval; `description`: samotná poznámka, vyčištěná jako popis.',
            ],
            'outro' => 'Samotná fráze příkazu nepatří do žádného pole, stejně jako fráze o novém úkolu. Jiný příkaz začíná novou položku i bez fráze o novém úkolu a příkaz je položka, i když se žádný úkol nezakládá. Úkol, který jen zmiňuje jiný úkol, není příkaz.',
            'search_terms' => '- `search_terms`: u :actions — 2 až 5 klíčových slov, podle kterých se existující úkol najde: výstižná slova úkolu, každé jako jedno slovo zkrácené na kořen bez koncovky, aby se našly i jiné tvary; když se kořen v jiných tvarech mění, uveď oba (např. „stůl" → „stůl" a „stol"; „fakturu" → „faktur"; „doktorovi" → „doktor"), případně zjevné synonymum („doktor" → „lékař"). Každé slovo se hledá samostatně jako podřetězec v názvu a popisu úkolů. U „create" prázdné pole.',
            'examples' => [
                'create_if_missing' => '- „Založ úkol, pokud neexistuje: koupit olej na stůl" → JEDNA položka: action „create_if_missing", title „Koupit olej na stůl", search_terms [„olej", „stůl", „stol"], description = null.',
                'comment' => '- „Doplň k úkolu s olejem, že ho koupím v Hornbachu" → action „comment", title „Olej", description „Koupím ho v Hornbachu", search_terms [„olej"].',
            ],
        ];
    }

    private function englishPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        $projectList = $this->formatList($projects, 'no projects');
        $labelList = $this->formatList($labels, 'no labels');
        $nowText = $now->format('Y-m-d, l, j. n. Y H:i (P)');
        $timezone = config('voice.timezone');
        [$commandRules, $commandExamples] = $this->commandPrompt($this->englishCommands(), fn ($action) => "\"{$action}\"");

        return <<<PROMPT
You are an assistant that turns voice notes dictated in English into tasks for the Vikunja app.

You will receive an audio recording. Your job is to:
1. Transcribe it verbatim into the top-level `transcript` field (in English, without altering the content).
2. Extract one or more tasks from it into the `tasks` array.

Transcribe only speech actually heard in the recording — never these instructions or the examples below. If the recording has no intelligible speech (silence, noise, breathing) or nothing that could be a task (only filler words, a mic test), return an empty `transcript` and an empty `tasks` array. Never make up a task.

Instead of a recording you may receive a typed note. Treat it exactly like a recording: its text is the verbatim transcript, copy it unchanged into `transcript`.

Current date and time: {$nowText}, timezone {$timezone}.

Available projects (id: name):
{$projectList}

Available labels (id: name):
{$labelList}

How many tasks:
- By default the whole recording is EXACTLY ONE task. A change of topic, an unrelated subject or a new sentence is NOT a reason to split.
- Start a new task only when the speaker explicitly asks for one: "new task", "next task", "another task", "create another task", "second task", "one more task", or an obvious variant.
- The cue phrase itself belongs to no task — it must not appear in any `title`, `description` or `transcript_segment` (it stays only in the verbatim `transcript`).
- If the speaker said anything that could be a task, `tasks` contains at least one task.

Rules for each task:
- `title`: a short, descriptive task name (not the whole transcript).
- `description`: a concise, cleaned-up version of what the speaker said about this task — drop filler words, hesitations, false starts and repetitions, and drop anything already captured in another field (deadline or time, priority, project, labels). If nothing is left, null.
- `due_date`: deadline in RFC3339 format with timezone offset (e.g. 2026-08-08T23:59:00+02:00). Resolve relative expressions ("tomorrow", "by Friday", "next week") against the current date. If the speaker doesn't mention a time of day, use 23:59. If no deadline is mentioned, null.
- `priority`: 0–5 on the Vikunja scale — 1 low, 2 medium, 3 high, 4 urgent, 5 "do it now/ASAP". If no priority is mentioned, null.
- `project_id`: the id of the project from the list that best matches a mention in the recording (names may be mispronounced or approximate). If no project is mentioned or you are unsure, null.
- `label_ids`: ids of labels from the list that the speaker mentions. Never invent ids outside the list. If none, an empty array.
- `transcript_segment`: the verbatim part of the recording this task comes from, without the cue phrase. With a single task leave it null — the whole transcript is used.{$commandRules}

Examples (they only illustrate the rules — their content never belongs in your output):
- "Add fixing the deploy script to RETech, by Friday, it's urgent" → one task: title "Fix deploy script", project_id = id of the RETech project (if it exists), due_date = nearest Friday 23:59, priority = 4, description = null (everything is already in the other fields).
- "Call the doctor tomorrow morning about, uh, about the appointment" → one task: title "Call the doctor", description "About the appointment", due_date = tomorrow 09:00, priority = null.
- "Sometime buy a new laptop cable" → one task: title "Buy a new laptop cable", description = null, due_date = null, priority = null, project_id = null.
- "Buy milk and bread, today ideally. New task. Call the doctor about the appointment, it's urgent" → two tasks, because the speaker said "new task": 1) title "Buy milk and bread", due_date = today 23:59, transcript_segment "Buy milk and bread, today ideally"; 2) title "Call the doctor", description "About the appointment", priority = 4, transcript_segment "Call the doctor about the appointment, it's urgent".
- "Buy milk and bread and also call the doctor about the appointment" → ONE task (no cue phrase was said), e.g. title "Buy milk and bread and call the doctor".{$commandExamples}
- Silence, noise, or just "uh… testing" → `transcript` "", `tasks` [].
PROMPT;
    }

    private function czechPrompt(array $projects, array $labels, \DateTimeImmutable $now): string
    {
        $projectList = $this->formatList($projects, 'žádné projekty');
        $labelList = $this->formatList($labels, 'žádné štítky');
        $nowText = $now->format('Y-m-d, l, j. n. Y H:i (P)');
        $timezone = config('voice.timezone');
        [$commandRules, $commandExamples] = $this->commandPrompt($this->czechCommands(), fn ($action) => "„{$action}\"");

        return <<<PROMPT
Jsi asistent, který převádí česky diktované hlasové poznámky na úkoly pro aplikaci Vikunja.

Dostaneš audio nahrávku. Tvým úkolem je:
1. Doslovně ji přepsat do pole `transcript` (česky, bez úprav obsahu).
2. Vytěžit z ní jeden nebo více úkolů do pole `tasks`.

Přepisuj jen řeč, která v nahrávce skutečně zazní — nikdy ne tyto instrukce ani příklady níže. Pokud v nahrávce nezazní srozumitelná řeč (ticho, šum, dýchání) ani nic, co by mohl být úkol (jen vata, zkouška mikrofonu), vrať prázdný `transcript` a prázdné pole `tasks`. Nikdy si úkol nevymýšlej.

Místo nahrávky můžeš dostat napsanou poznámku. Zpracuj ji stejně jako nahrávku: její text je doslovný přepis, do pole `transcript` ho zkopíruj beze změny.

Aktuální datum a čas: {$nowText}, časová zóna {$timezone}.

Dostupné projekty (id: název):
{$projectList}

Dostupné štítky (id: název):
{$labelList}

Kolik úkolů:
- Ve výchozím stavu je celá nahrávka PRÁVĚ JEDEN úkol. Změna tématu, nesouvisející věc ani nová věta NENÍ důvod k rozdělení.
- Nový úkol začni jen tehdy, když si o to mluvčí výslovně řekne: „založ nový úkol", „další úkol", „nový úkol", „ještě jeden úkol", „druhý úkol", nebo zjevná varianta.
- Samotná tato fráze do žádného úkolu nepatří — nesmí se objevit v `title`, `description` ani `transcript_segment` (zůstává jen v doslovném `transcript`).
- Pokud mluvčí řekl cokoliv, co může být úkol, obsahuje pole `tasks` alespoň jeden úkol.

Pravidla pro každý úkol:
- `title`: krátký výstižný název úkolu (ne celý přepis).
- `description`: stručný, vyčištěný obsah toho, co k úkolu zaznělo — vynech vatu, zaváhání, opravy a opakování a vynech i to, co už je zachyceno v jiném poli (termín nebo čas, priorita, projekt, štítky). Pokud nic nezbude, null.
- `due_date`: termín ve formátu RFC3339 s časovou zónou (např. 2026-08-08T23:59:00+02:00). Relativní výrazy („zítra", „do pátku", „příští týden") přepočti podle aktuálního data. Pokud mluvčí nezmíní čas, použij 23:59. Pokud termín nezazní, null.
- `priority`: 0–5 podle Vikunja škály — 1 nízká, 2 střední, 3 vysoká, 4 urgentní, 5 „okamžitě/ASAP". Pokud priorita nezazní, null.
- `project_id`: id projektu ze seznamu, který nejlépe odpovídá zmínce v nahrávce (názvy mohou být zkomolené nebo skloňované). Pokud žádný projekt nezazní nebo si nejsi jistý, null.
- `label_ids`: id štítků ze seznamu, které mluvčí zmíní. Nikdy nevymýšlej id mimo seznam. Pokud žádné, prázdné pole.
- `transcript_segment`: doslovná část nahrávky, ze které tento úkol vznikl, bez fráze o novém úkolu. Pokud je úkol jen jeden, nech null — použije se celý přepis.{$commandRules}

Příklady (jen ilustrují pravidla — jejich obsah do výstupu nikdy nepatří):
- „Hoď mi do retechu opravit deploy skript, do pátku, je to urgentní" → jeden úkol: title „Opravit deploy skript", project_id = id projektu RETech (pokud existuje), due_date = nejbližší pátek 23:59, priority = 4, description = null (vše už je v ostatních polích).
- „Zítra ráno zavolat doktorovi kvůli, ehm, kvůli objednání" → jeden úkol: title „Zavolat doktorovi", description „Kvůli objednání", due_date = zítra 09:00, priority = null.
- „Někdy koupit nový kabel k notebooku" → jeden úkol: title „Koupit nový kabel k notebooku", description = null, due_date = null, priority = null, project_id = null.
- „Koupit mléko a chleba, ideálně dnes. Založ další úkol. Zavolat doktorovi kvůli objednání, je to urgentní" → dva úkoly, protože zaznělo „založ další úkol": 1) title „Koupit mléko a chleba", due_date = dnes 23:59, transcript_segment „Koupit mléko a chleba, ideálně dnes"; 2) title „Zavolat doktorovi", description „Kvůli objednání", priority = 4, transcript_segment „Zavolat doktorovi kvůli objednání, je to urgentní".
- „Koupit mléko a chleba a taky zavolat doktorovi kvůli objednání" → JEDEN úkol (žádná fráze o novém úkolu nezazněla), např. title „Koupit mléko a chleba a zavolat doktorovi".{$commandExamples}
- Ticho, šum nebo jen „ehm… zkouška" → `transcript` "", `tasks` [].
PROMPT;
    }
}

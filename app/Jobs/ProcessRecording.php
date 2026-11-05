<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\ExistingTaskMatcher;
use App\Services\ExtractionValidator;
use App\Services\GeminiClient;
use App\Services\Ntfy;
use App\Services\VikunjaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sentry\Severity;
use Sentry\State\Scope;

class ProcessRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public string $recordingId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(GeminiClient $gemini, VikunjaClient $vikunja, Ntfy $ntfy, ExistingTaskMatcher $matcher): void
    {
        $recording = Recording::find($this->recordingId);

        if ($recording === null || $recording->status === 'done') {
            return;
        }

        $recording->update(['status' => 'processing']);

        $extraction = $recording->extraction;
        // One entry per item carried out: the task created or acted on, null
        // when there is none. Its length is where a retry resumes.
        $taskIds = array_map(fn ($id) => $id === null ? null : (int) $id, $recording->vikunja_task_ids ?? []);

        if (! is_array($extraction['tasks'] ?? null) || $extraction['tasks'] === []) {
            if ($taskIds !== []) {
                // Tasks exist but the extraction is gone: running Gemini again
                // could split differently and duplicate them. Fail loudly.
                throw new \RuntimeException("Recording {$recording->id} has created tasks but no usable extraction");
            }

            $extraction = $this->extract($recording, $gemini, $vikunja);

            // Persist before creating anything: a retry reuses this instead of
            // calling Gemini again, and the transcript survives a later failure.
            $recording->update([
                'extraction' => $extraction,
                'transcript' => $extraction['transcript'],
                'vikunja_task_ids' => [],
            ]);
        }

        // Decided and stored before anything is created: a retry neither asks
        // again nor creates what was found to exist. Runs only on request,
        // so a plain recording never reads the task list.
        if (array_filter($extraction['tasks'], ExistingTaskMatcher::pending(...)) !== []) {
            $extraction = $matcher->resolve($extraction);
            $recording->update(['extraction' => $extraction]);
        }

        $tasks = $extraction['tasks'];
        $total = count($tasks);

        if ($total === 0) {
            // Silence or nothing task-like. Sent even with success
            // notifications off: otherwise the note just vanishes.
            $ntfy->send('🤷 '.$this->say($recording->text !== null ? 'no_tasks_note' : 'no_tasks'));
        }

        for ($i = count($taskIds); $i < $total; $i++) {
            $task = $tasks[$i];
            $description = $this->buildDescription(
                $task['description'],
                $this->transcriptFor($extraction, $i),
                $i + 1,
                $total,
                $recording->text !== null,
            );

            // Carry the item out; anything but a plain new task is announced
            // even with success notifications off, since either nothing new
            // shows up in Vikunja or the model picked the task acted on.
            [$taskId, $message] = match ($task['action'] ?? 'create') {
                'create_if_missing' => $this->createIfMissing($vikunja, $task, $description),
                'comment' => $this->comment($vikunja, $task, $description),
                'complete' => $this->complete($vikunja, $task),
                'update' => $this->updateExisting($vikunja, $task),
                default => $this->create($vikunja, $task, $description),
            };

            // Record before notifying: a retry skips this item, so it is
            // neither carried out nor announced twice.
            $taskIds[] = $taskId;
            $recording->update(['vikunja_task_ids' => $taskIds]);

            if ($message !== null) {
                $ntfy->send($message, config('voice.vikunja.url').($taskId !== null ? "/tasks/{$taskId}" : ''));
            }
        }

        $recording->update(['status' => 'done']);
    }

    public function failed(?\Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'unknown';

        // `extraction` and `vikunja_task_ids` are kept on purpose, so a manual
        // queue:retry resumes from the first task that was not created yet.
        Recording::where('id', $this->recordingId)->update([
            'status' => 'failed',
            'error' => $error,
        ]);

        // Every attempt's exception already reaches Sentry via the standard
        // handler; this distinct fatal event fires only when all retries are
        // exhausted, so alert rules can target terminal_failure alone.
        \Sentry\withScope(function (Scope $scope) use ($exception, $error): void {
            $scope->setLevel(Severity::fatal());
            $scope->setTag('job', 'process_recording');
            $scope->setTag('terminal_failure', 'true');
            $scope->setContext('recording', [
                'id' => $this->recordingId,
                'error' => $error,
            ]);
            \Sentry\captureException($exception ?? new \RuntimeException('ProcessRecording failed without exception'));
        });

        app(Ntfy::class)->send('❌ '.__('voice.processing_failed', locale: config('voice.locale')).': '.$error);
    }

    /**
     * Run Gemini over the audio (or the typed note) and validate the result
     * against live Vikunja data. The auto label is baked in here so the
     * persisted extraction is self-contained and a retry needs no Vikunja lookups.
     */
    private function extract(Recording $recording, GeminiClient $gemini, VikunjaClient $vikunja): array
    {
        $projects = $vikunja->projects();
        $labels = $vikunja->labels();
        $now = new \DateTimeImmutable('now', new \DateTimeZone(config('voice.timezone')));

        $raw = $recording->text !== null
            ? $gemini->extractFromText($recording->text, $projects, $labels, $now)
            : $gemini->extract(
                Storage::disk('local')->get($recording->path),
                $recording->mime,
                $projects,
                $labels,
                $now,
            );

        $extraction = ExtractionValidator::validate(
            $raw,
            validProjectIds: array_column($projects, 'id'),
            validLabelIds: array_column($labels, 'id'),
            defaultProjectId: (int) config('voice.vikunja.default_project_id'),
            fallbackTitle: __('voice.fallback_title', ['time' => $now->format('j. n. Y H:i')], locale: config('voice.locale')),
        );

        // A typed note is its own transcript, whatever the model echoed back.
        if ($recording->text !== null) {
            $extraction['transcript'] = trim($recording->text);
        }

        foreach ($extraction['tasks'] as $i => $task) {
            $extraction['tasks'][$i]['label_ids'] = $this->withAutoLabel($task['label_ids'], $labels);
        }

        return $extraction;
    }

    /**
     * @return array{0: int, 1: ?string} the new task's id and its announcement
     */
    private function create(VikunjaClient $vikunja, array $task, string $description): array
    {
        $payload = [
            'title' => $task['title'],
            'description' => $description,
            'priority' => $task['priority'],
        ];

        if ($task['due_date'] !== null) {
            $payload['due_date'] = $task['due_date'];
        }

        if (($task['reminders'] ?? []) !== []) {
            $payload['reminders'] = array_map(fn (string $at) => ['reminder' => $at], $task['reminders']);
        }

        $created = $vikunja->createTask($task['project_id'], $payload);
        $taskId = (int) $created['id'];

        foreach ($task['label_ids'] as $labelId) {
            try {
                $vikunja->attachLabel($taskId, $labelId);
            } catch (\Throwable $e) {
                Log::warning('Label attach failed', ['task' => $taskId, 'label' => $labelId, 'error' => $e->getMessage()]);
            }
        }

        $this->autoAssign($vikunja, $taskId);

        return [$taskId, config('voice.ntfy.notify_success') ? "✅ {$task['title']}" : null];
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function createIfMissing(VikunjaClient $vikunja, array $task, string $description): array
    {
        $existing = $task['match'];

        return $existing['task_id'] !== null
            ? [$existing['task_id'], '🔁 '.$this->say('task_exists', ['title' => $existing['title']])]
            : $this->create($vikunja, $task, $description);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function comment(VikunjaClient $vikunja, array $task, string $description): array
    {
        $existing = $task['match'];

        if ($existing['task_id'] === null) {
            // Nothing to attach the note to: kept as a task of its own rather than lost.
            [$taskId] = $this->create($vikunja, $task, $description);

            return [$taskId, '📝 '.$this->say('comment_as_task', ['title' => $task['title']])];
        }

        $vikunja->addComment($existing['task_id'], $description);

        return [$existing['task_id'], '💬 '.$this->say('comment_added', ['title' => $existing['title']])];
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function complete(VikunjaClient $vikunja, array $task): array
    {
        $existing = $task['match'];

        if ($existing['task_id'] === null) {
            return [null, '❓ '.$this->say('task_not_found', ['title' => $task['title']])];
        }

        $vikunja->updateTask($existing['task_id'], fn (array $current) => [...$current, 'done' => true]);

        return [$existing['task_id'], '☑️ '.$this->say('task_completed', ['title' => $existing['title']])];
    }

    /**
     * Only what was said changes: a null due date or a zero priority means
     * "not mentioned", and reminders are added to those the task has.
     *
     * @return array{0: ?int, 1: string}
     */
    private function updateExisting(VikunjaClient $vikunja, array $task): array
    {
        $existing = $task['match'];

        if ($existing['task_id'] === null) {
            return [null, '❓ '.$this->say('task_not_found', ['title' => $task['title']])];
        }

        $changes = array_filter([
            'due_date' => $task['due_date'],
            'priority' => $task['priority'] ?: null,
        ]);
        $reminders = $task['reminders'] ?? [];

        if ($changes === [] && $reminders === []) {
            return [$existing['task_id'], '✏️ '.$this->say('task_unchanged', ['title' => $existing['title']])];
        }

        $vikunja->updateTask($existing['task_id'], fn (array $current) => [
            ...$current,
            ...$changes,
            'reminders' => [
                ...($current['reminders'] ?? []),
                ...array_map(fn (string $at) => ['reminder' => $at], $reminders),
            ],
        ]);

        $described = [
            ...(isset($changes['due_date']) ? [$this->say('change_due', ['at' => $this->formatTime($changes['due_date'])])] : []),
            ...(isset($changes['priority']) ? [$this->say('change_priority', ['priority' => $changes['priority']])] : []),
            ...array_map(fn (string $at) => $this->say('change_reminder', ['at' => $this->formatTime($at)]), $reminders),
        ];

        return [$existing['task_id'], '✏️ '.$this->say('task_updated', [
            'title' => $existing['title'],
            'changes' => implode(', ', $described),
        ])];
    }

    private function formatTime(string $at): string
    {
        return (new \DateTimeImmutable($at))
            ->setTimezone(new \DateTimeZone(config('voice.timezone')))
            ->format($this->say('time_format'));
    }

    // Explicit locale: queue workers don't run the SetVoiceLocale HTTP middleware.
    private function say(string $key, array $replace = []): string
    {
        return __("voice.{$key}", $replace, locale: config('voice.locale'));
    }

    private function withAutoLabel(array $labelIds, array $labels): array
    {
        $autoLabel = (string) config('voice.vikunja.auto_label');

        if ($autoLabel === '') {
            return $labelIds;
        }

        foreach ($labels as $label) {
            if (strcasecmp($label['title'], $autoLabel) === 0) {
                return in_array($label['id'], $labelIds, true) ? $labelIds : [...$labelIds, $label['id']];
            }
        }

        Log::warning('Auto label not found in Vikunja', ['label' => $autoLabel]);

        return $labelIds;
    }

    private function autoAssign(VikunjaClient $vikunja, int $taskId): void
    {
        $username = (string) config('voice.vikunja.auto_assign_username');

        if ($username === '') {
            return;
        }

        try {
            $userId = $vikunja->findUserId($username);

            if ($userId === null) {
                Log::warning('Auto assign user not found', ['username' => $username]);

                return;
            }

            $vikunja->assignUser($taskId, $userId);
        } catch (\Throwable $e) {
            Log::warning('Auto assign failed', ['task' => $taskId, 'username' => $username, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A single task keeps the whole recording under the rule, exactly as
     * before. With several tasks each gets its own verbatim segment, falling
     * back to the whole recording if the model left the segment empty.
     */
    private function transcriptFor(array $extraction, int $index): string
    {
        $whole = (string) $extraction['transcript'];
        $segment = trim((string) ($extraction['tasks'][$index]['transcript'] ?? ''));

        if (count($extraction['tasks']) === 1) {
            return $whole !== '' ? $whole : $segment;
        }

        return $segment !== '' ? $segment : $whole;
    }

    private function buildDescription(string $description, string $transcript, int $index, int $total, bool $typed): string
    {
        $html = $description !== '' ? '<p>'.e($description).'</p>' : '';
        $key = $typed ? 'voice.note_label' : 'voice.transcript_label';

        // Explicit locale: queue workers don't run the SetVoiceLocale HTTP middleware.
        $label = $total > 1
            ? __("{$key}_multi", ['index' => $index, 'total' => $total], locale: config('voice.locale'))
            : __($key, locale: config('voice.locale'));

        return $html.'<hr><p><em>'.e($label).':</em> '.e($transcript).'</p>';
    }
}

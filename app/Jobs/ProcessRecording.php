<?php

namespace App\Jobs;

use App\Models\Recording;
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

    public function handle(GeminiClient $gemini, VikunjaClient $vikunja, Ntfy $ntfy): void
    {
        $recording = Recording::find($this->recordingId);

        if ($recording === null || $recording->status === 'done') {
            return;
        }

        $recording->update(['status' => 'processing']);

        $extraction = $recording->extraction;
        $createdIds = array_map(intval(...), $recording->vikunja_task_ids ?? []);

        if (! is_array($extraction['tasks'] ?? null) || $extraction['tasks'] === []) {
            if ($createdIds !== []) {
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

        $tasks = $extraction['tasks'];
        $total = count($tasks);

        if ($total === 0) {
            // Silence or nothing task-like. Sent even with success
            // notifications off: otherwise the note just vanishes.
            $key = $recording->text !== null ? 'voice.no_tasks_note' : 'voice.no_tasks';
            $ntfy->send('🤷 '.__($key, locale: config('voice.locale')));
        }

        for ($i = count($createdIds); $i < $total; $i++) {
            $task = $tasks[$i];

            $payload = [
                'title' => $task['title'],
                'description' => $this->buildDescription(
                    $task['description'],
                    $this->transcriptFor($extraction, $i),
                    $i + 1,
                    $total,
                    $recording->text !== null,
                ),
                'priority' => $task['priority'],
            ];

            if ($task['due_date'] !== null) {
                $payload['due_date'] = $task['due_date'];
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

            // Record the id before notifying: a retry skips this task, so it
            // can neither be created nor announced twice.
            $createdIds[] = $taskId;
            $recording->update(['vikunja_task_ids' => $createdIds]);

            if (config('voice.ntfy.notify_success')) {
                $ntfy->send("✅ {$task['title']}", config('voice.vikunja.url')."/tasks/{$taskId}");
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

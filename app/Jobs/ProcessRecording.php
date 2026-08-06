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

class ProcessRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public string $recordingId)
    {
    }

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

        $projects = $vikunja->projects();
        $labels = $vikunja->labels();
        $now = new \DateTimeImmutable('now', new \DateTimeZone(config('voice.timezone')));

        $raw = $gemini->extract(
            Storage::disk('local')->get($recording->path),
            $recording->mime,
            $projects,
            $labels,
            $now,
        );

        $task = ExtractionValidator::validate(
            $raw,
            validProjectIds: array_column($projects, 'id'),
            validLabelIds: array_column($labels, 'id'),
            defaultProjectId: (int) config('voice.vikunja.default_project_id'),
            now: $now,
        );

        $payload = [
            'title' => $task['title'],
            'description' => $this->buildDescription($task['description'], $task['transcript']),
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

        $recording->update([
            'status' => 'done',
            'transcript' => $task['transcript'],
            'vikunja_task_id' => $taskId,
        ]);

        if (config('voice.ntfy.notify_success')) {
            $ntfy->send("✅ {$task['title']}");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Recording::where('id', $this->recordingId)->update([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'unknown',
        ]);

        app(Ntfy::class)->send('❌ Zpracování nahrávky selhalo: '.($exception?->getMessage() ?? 'unknown'));
    }

    private function buildDescription(string $description, string $transcript): string
    {
        $html = $description !== '' ? '<p>'.e($description).'</p>' : '';

        return $html.'<hr><p><em>Přepis:</em> '.e($transcript).'</p>';
    }
}

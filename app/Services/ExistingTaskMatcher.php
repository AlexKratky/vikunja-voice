<?php

namespace App\Services;

class ExistingTaskMatcher
{
    /** Vikunja searches per recording, on top of the newest open tasks. */
    private const MAX_SEARCHES = 10;

    public function __construct(
        private GeminiClient $gemini,
        private VikunjaClient $vikunja,
    ) {}

    /**
     * Whether an item still waits for its existing task to be looked up.
     */
    public static function pending(array $task): bool
    {
        return in_array($task['action'] ?? null, ExtractionValidator::LOOKUP_ACTIONS, true)
            && ! array_key_exists('match', $task);
    }

    /**
     * Give every pending item a `match`: the open Vikunja task it is about
     * (`task_id` null when none is). The candidates are the newest open tasks
     * plus open tasks containing any of the search terms; the model picks
     * among them, so it can only point at a task that exists.
     */
    public function resolve(array $extraction): array
    {
        $pending = array_filter($extraction['tasks'], self::pending(...));

        if ($pending === []) {
            return $extraction;
        }

        $openTasks = $this->openTasks(array_merge(...array_column($pending, 'search_terms')));
        $decisions = $openTasks === [] ? [] : $this->decide($pending, $openTasks);

        foreach (array_keys($pending) as $n => $i) {
            $decision = $decisions[$n + 1] ?? [];
            $match = $openTasks[(int) ($decision['existing_task_id'] ?? 0)] ?? null;

            $extraction['tasks'][$i]['match'] = [
                'task_id' => $match['id'] ?? null,
                'title' => $match['title'] ?? null,
                'reason' => trim((string) ($decision['reason'] ?? '')),
            ];
        }

        return $extraction;
    }

    /**
     * @param  list<string>  $terms
     * @return array<int, array{id: int, title: string, project_id: int}> keyed by id
     */
    private function openTasks(array $terms): array
    {
        $tasks = array_column($this->vikunja->openTasks(), null, 'id');

        foreach (array_slice(array_values(array_unique($terms)), 0, self::MAX_SEARCHES) as $term) {
            $tasks += array_column($this->vikunja->openTasks($term), null, 'id');
        }

        return $tasks;
    }

    /**
     * @return array<int, array<string, mixed>> the model's decisions keyed by task number (1-based)
     */
    private function decide(array $pending, array $openTasks): array
    {
        $projects = array_column($this->vikunja->projects(), 'title', 'id');

        $raw = $this->gemini->matchExisting(
            array_map(fn (array $task) => [
                'kind' => $task['action'] === 'create_if_missing' ? 'new' : 'existing',
                'title' => $task['title'],
                'description' => $task['description'],
            ], array_values($pending)),
            array_map(fn (array $task) => [
                'id' => $task['id'],
                'title' => $task['title'],
                'project' => $projects[$task['project_id']] ?? '?',
            ], array_values($openTasks)),
        );

        $decisions = [];

        foreach (is_array($raw['decisions'] ?? null) ? $raw['decisions'] : [] as $decision) {
            if (is_array($decision) && is_numeric($decision['task'] ?? null)) {
                $decisions[(int) $decision['task']] = $decision;
            }
        }

        return $decisions;
    }
}

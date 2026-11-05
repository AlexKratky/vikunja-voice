<?php

namespace App\Services;

class ExtractionValidator
{
    /** What an item can do; `create` is the default and always available. */
    public const ACTIONS = ['create', 'create_if_missing'];

    /** Actions about an existing task, which has to be found first. */
    public const LOOKUP_ACTIONS = ['create_if_missing'];

    /**
     * Sanitize a raw Gemini extraction into a whole-recording transcript plus
     * its tasks (none if nothing in it is a task). Framework-free on purpose:
     * unit tests run it without booting Laravel, so the locale-dependent
     * fallback title is passed in.
     *
     * @param  array<int, int>  $validProjectIds
     * @param  array<int, int>  $validLabelIds
     * @return array{transcript: string, tasks: list<array<string, mixed>>}
     */
    public static function validate(
        array $raw,
        array $validProjectIds,
        array $validLabelIds,
        int $defaultProjectId,
        string $fallbackTitle,
    ): array {
        $rawTasks = is_array($raw['tasks'] ?? null)
            ? array_values(array_filter($raw['tasks'], is_array(...)))
            : [];

        // An empty list with no top-level title is the model reporting that
        // nothing in the recording is a task (silence, noise).
        $noTask = ($raw['tasks'] ?? null) === [] && trim((string) ($raw['title'] ?? '')) === '';

        // Missing, empty or malformed `tasks`: treat the top-level object as a
        // single task, so the old flat response shape still yields a task.
        if ($rawTasks === [] && ! $noTask) {
            $rawTasks = [$raw];
        }

        return [
            'transcript' => trim((string) ($raw['transcript'] ?? '')),
            'tasks' => array_map(
                fn (array $task) => self::task($task, $validProjectIds, $validLabelIds, $defaultProjectId, $fallbackTitle),
                $rawTasks,
            ),
        ];
    }

    /**
     * @param  array<int, int>  $validProjectIds
     * @param  array<int, int>  $validLabelIds
     */
    private static function task(
        array $raw,
        array $validProjectIds,
        array $validLabelIds,
        int $defaultProjectId,
        string $fallbackTitle,
    ): array {
        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '') {
            $title = $fallbackTitle;
        }

        $dueDate = null;
        if (is_string($raw['due_date'] ?? null) && trim($raw['due_date']) !== '') {
            try {
                $dueDate = (new \DateTimeImmutable($raw['due_date']))->format(DATE_RFC3339);
            } catch (\Exception) {
                $dueDate = null;
            }
        }

        $priority = min(5, max(0, (int) ($raw['priority'] ?? 0)));

        $projectId = (int) ($raw['project_id'] ?? 0);
        if (! in_array($projectId, $validProjectIds, true)) {
            $projectId = $defaultProjectId;
        }

        $labelIds = array_values(array_intersect(
            array_map(intval(...), is_array($raw['label_ids'] ?? null) ? $raw['label_ids'] : []),
            $validLabelIds,
        ));

        return [
            'title' => $title,
            'description' => trim((string) ($raw['description'] ?? '')),
            'due_date' => $dueDate,
            'priority' => $priority,
            'project_id' => $projectId,
            'label_ids' => $labelIds,
            // Wire name is `transcript_segment`; `transcript` is accepted so the
            // flat old-shape fallback above carries the whole recording.
            'transcript' => trim((string) ($raw['transcript_segment'] ?? $raw['transcript'] ?? '')),
            // Anything unexpected creates the task: a duplicate beats a lost note.
            'action' => in_array($raw['action'] ?? null, self::ACTIONS, true) ? $raw['action'] : 'create',
            'search_terms' => self::searchTerms($raw['search_terms'] ?? null),
        ];
    }

    /**
     * Each term becomes one Vikunja substring search: one or two letters
     * would match nearly every task, and a handful of terms is plenty.
     *
     * @return list<string>
     */
    private static function searchTerms(mixed $raw): array
    {
        $terms = [];

        foreach (is_array($raw) ? $raw : [] as $term) {
            $term = is_string($term) ? trim($term) : '';

            if (mb_strlen($term) >= 3 && ! in_array(mb_strtolower($term), array_map(mb_strtolower(...), $terms), true)) {
                $terms[] = $term;
            }
        }

        return array_slice($terms, 0, 5);
    }
}

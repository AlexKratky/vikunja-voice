<?php

namespace App\Services;

class ExtractionValidator
{
    /**
     * @param  array<int, int>  $validProjectIds
     * @param  array<int, int>  $validLabelIds
     */
    public static function validate(
        array $raw,
        array $validProjectIds,
        array $validLabelIds,
        int $defaultProjectId,
        \DateTimeImmutable $now,
    ): array {
        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '') {
            $title = 'Nahrávka '.$now->format('j. n. Y H:i');
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
            'transcript' => trim((string) ($raw['transcript'] ?? '')),
        ];
    }
}

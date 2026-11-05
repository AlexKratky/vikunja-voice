<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VikunjaClient
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(config('voice.vikunja.url').'/api/v1')
            ->withToken(config('voice.vikunja.token'))
            ->timeout(30);
    }

    public function projects(): array
    {
        $projects = $this->client()->get('/projects')->throw()->json() ?? [];

        // Vikunja lists pseudo-projects (Favorites, My Open Tasks, …) with
        // negative ids; tasks cannot be created in them.
        return array_values(array_filter(array_map(
            fn ($p) => ['id' => (int) $p['id'], 'title' => (string) $p['title']],
            $projects,
        ), fn ($p) => $p['id'] > 0));
    }

    public function labels(): array
    {
        $labels = $this->client()->get('/labels')->throw()->json() ?? [];

        return array_map(
            fn ($l) => ['id' => (int) $l['id'], 'title' => (string) $l['title']],
            $labels,
        );
    }

    /**
     * Open tasks across all projects, newest first, at most one page (Vikunja
     * caps it at its max_items_per_page, 50 by default). With a search, only
     * tasks containing it in their title or description — as one substring,
     * so pass single words.
     */
    public function openTasks(?string $search = null): array
    {
        $query = ['filter' => 'done = false', 'sort_by' => 'created', 'order_by' => 'desc', 'per_page' => 50];

        if ($search !== null) {
            $query['s'] = $search;
        }

        $tasks = $this->client()->get('/tasks', $query)->throw()->json() ?? [];

        return array_map(
            fn ($t) => ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'project_id' => (int) $t['project_id']],
            $tasks,
        );
    }

    public function createTask(int $projectId, array $payload): array
    {
        return $this->client()->put("/projects/{$projectId}/tasks", $payload)->throw()->json();
    }

    /**
     * Vikunja's update overwrites every field, clearing any left out (a bare
     * `{"done": true}` wipes the description, due date, priority and
     * reminders), so the task is read and sent back whole with the change.
     *
     * @param  \Closure(array): array  $change
     */
    public function updateTask(int $taskId, \Closure $change): array
    {
        $task = $this->client()->get("/tasks/{$taskId}")->throw()->json();

        return $this->client()->post("/tasks/{$taskId}", $change($task))->throw()->json();
    }

    public function addComment(int $taskId, string $html): void
    {
        $this->client()->put("/tasks/{$taskId}/comments", ['comment' => $html])->throw();
    }

    public function findUserId(string $username): ?int
    {
        $users = $this->client()->get('/users', ['s' => $username])->throw()->json() ?? [];

        foreach ($users as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                return (int) $user['id'];
            }
        }

        return null;
    }

    public function assignUser(int $taskId, int $userId): void
    {
        $this->client()->put("/tasks/{$taskId}/assignees", ['user_id' => $userId])->throw();
    }

    public function attachLabel(int $taskId, int $labelId): void
    {
        $this->client()->put("/tasks/{$taskId}/labels", ['label_id' => $labelId])->throw();
    }
}

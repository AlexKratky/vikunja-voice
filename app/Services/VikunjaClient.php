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

    public function createTask(int $projectId, array $payload): array
    {
        return $this->client()->put("/projects/{$projectId}/tasks", $payload)->throw()->json();
    }

    public function attachLabel(int $taskId, int $labelId): void
    {
        $this->client()->put("/tasks/{$taskId}/labels", ['label_id' => $labelId])->throw();
    }
}

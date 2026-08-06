<?php

use App\Models\Recording;

it('creates a recording with uuid primary key and defaults', function () {
    $recording = Recording::factory()->create();

    expect($recording->id)->toBeString()->toHaveLength(36)
        ->and($recording->status)->toBe('pending')
        ->and($recording->transcript)->toBeNull()
        ->and($recording->vikunja_task_id)->toBeNull()
        ->and($recording->audio_deleted_at)->toBeNull();

    expect(Recording::find($recording->id))->not->toBeNull();
});

<?php

use App\Models\Recording;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('voice.retention_days', 7);
    Storage::fake('local');
    $this->travelTo(now()->parse('2026-08-06 12:00:00'));
});

function makePruneRecording(string $status, int $daysOld, ?string $deletedAt = null): Recording
{
    $recording = Recording::factory()->create([
        'status' => $status,
        'audio_deleted_at' => $deletedAt,
        'created_at' => now()->subDays($daysOld),
    ]);
    Storage::disk('local')->put($recording->path, 'AUDIO');

    return $recording;
}

it('deletes audio of old done recordings and stamps audio_deleted_at', function () {
    $old = makePruneRecording('done', 10);

    $this->artisan('recordings:prune')->assertSuccessful();

    Storage::disk('local')->assertMissing($old->path);
    expect($old->refresh()->audio_deleted_at)->not->toBeNull();
});

it('keeps recent done recordings', function () {
    $recent = makePruneRecording('done', 3);

    $this->artisan('recordings:prune')->assertSuccessful();

    Storage::disk('local')->assertExists($recent->path);
});

it('never deletes failed or pending recordings', function () {
    $failed = makePruneRecording('failed', 30);
    $pending = makePruneRecording('pending', 30);

    $this->artisan('recordings:prune')->assertSuccessful();

    Storage::disk('local')->assertExists($failed->path);
    Storage::disk('local')->assertExists($pending->path);
});

it('skips already-pruned rows', function () {
    $pruned = makePruneRecording('done', 30, deletedAt: now()->subDays(20)->toDateTimeString());
    $stamp = $pruned->refresh()->audio_deleted_at;

    $this->artisan('recordings:prune')->assertSuccessful();

    expect($pruned->refresh()->audio_deleted_at->toDateTimeString())
        ->toBe($stamp->toDateTimeString());
});

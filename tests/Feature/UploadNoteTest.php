<?php

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('voice.upload_token', 'secret-token');
    Storage::fake('local');
    Queue::fake();
});

function postNote(mixed $text = 'zítra zavolat do banky', ?string $token = 'secret-token')
{
    $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

    return test()->postJson('/api/notes', ['text' => $text], $headers);
}

it('rejects missing token', function () {
    postNote(token: null)->assertStatus(401);
});

it('rejects wrong token', function () {
    postNote(token: 'wrong')->assertStatus(401);
});

it('rejects when no token is configured (fail closed)', function () {
    config()->set('voice.upload_token', '');
    postNote(token: '')->assertStatus(401);
});

it('rejects missing text', function () {
    test()->postJson('/api/notes', [], ['Authorization' => 'Bearer secret-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('rejects whitespace-only text', function () {
    postNote('   ')->assertStatus(422)->assertJsonValidationErrors('text');
});

it('rejects non-string text', function () {
    postNote(['a', 'b'])->assertStatus(422)->assertJsonValidationErrors('text');
});

it('rejects text over 2000 characters', function () {
    postNote(str_repeat('a', 2001))->assertStatus(422)->assertJsonValidationErrors('text');
});

it('answers validation errors with JSON even without an Accept header', function () {
    test()->post('/api/notes', [], ['Authorization' => 'Bearer secret-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('creates a row without audio, dispatches the job, returns 202', function () {
    $response = postNote('  zítra zavolat do banky  ');

    $response->assertStatus(202);
    $id = $response->json('id');
    expect($id)->toBeString();

    $recording = Recording::find($id);
    expect($recording)->not->toBeNull()
        ->and($recording->status)->toBe('pending')
        ->and($recording->text)->toBe('zítra zavolat do banky')
        ->and($recording->path)->toBeNull()
        ->and($recording->mime)->toBeNull();

    expect(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertPushed(ProcessRecording::class, fn ($job) => $job->recordingId === $id);
});

<?php

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('voice.upload_token', 'secret-token');
    Storage::fake('local');
    Queue::fake();
});

function upload(?string $token = 'secret-token', ?UploadedFile $file = null)
{
    $file ??= UploadedFile::fake()->create('recording.webm', 100, 'audio/webm');
    $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

    return test()->post('/api/recordings', ['audio' => $file], $headers);
}

it('rejects missing token', function () {
    upload(token: null)->assertStatus(401);
});

it('rejects wrong token', function () {
    upload(token: 'wrong')->assertStatus(401);
});

it('rejects when no token is configured (fail closed)', function () {
    config()->set('voice.upload_token', '');
    upload(token: '')->assertStatus(401);
});

it('rejects missing file', function () {
    test()->post('/api/recordings', [], ['Authorization' => 'Bearer secret-token'])
        ->assertStatus(422);
});

it('rejects non-audio file', function () {
    upload(file: UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->assertStatus(422);
});

it('rejects file over 20MB', function () {
    upload(file: UploadedFile::fake()->create('big.webm', 21000, 'audio/webm'))
        ->assertStatus(422);
});

it('stores file, creates row, dispatches job, returns 202', function () {
    $response = upload();

    $response->assertStatus(202);
    $id = $response->json('id');
    expect($id)->toBeString();

    $recording = Recording::find($id);
    expect($recording)->not->toBeNull()
        ->and($recording->status)->toBe('pending')
        ->and($recording->mime)->toBe('audio/webm')
        ->and($recording->path)->toBe("recordings/{$id}.webm");

    Storage::disk('local')->assertExists($recording->path);
    Queue::assertPushed(ProcessRecording::class, fn ($job) => $job->recordingId === $id);
});

it('accepts iPhone Safari audio/x-m4a uploads and stores them as .m4a', function () {
    $response = upload(file: UploadedFile::fake()->create('recording.m4a', 100, 'audio/x-m4a'));

    $response->assertStatus(202);
    $id = $response->json('id');

    $recording = Recording::find($id);
    expect($recording)->not->toBeNull()
        ->and($recording->mime)->toBe('audio/x-m4a')
        ->and($recording->path)->toBe("recordings/{$id}.m4a");

    Storage::disk('local')->assertExists($recording->path);
});

it('accepts iPhone Safari video/mp4 (mp42 brand) uploads and stores them as .m4a', function () {
    $response = upload(file: UploadedFile::fake()->create('recording.mp4', 100, 'video/mp4'));

    $response->assertStatus(202);
    $id = $response->json('id');

    $recording = Recording::find($id);
    expect($recording)->not->toBeNull()
        ->and($recording->mime)->toBe('video/mp4')
        ->and($recording->path)->toBe("recordings/{$id}.m4a");

    Storage::disk('local')->assertExists($recording->path);
});

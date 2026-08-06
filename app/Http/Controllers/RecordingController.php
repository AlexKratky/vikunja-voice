<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordingController extends Controller
{
    private const EXTENSIONS = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => [
                'required',
                'file',
                'max:20480',
                'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)),
            ],
        ]);

        $file = $request->file('audio');
        $mime = $file->getMimeType();
        $id = (string) Str::uuid();
        $extension = self::EXTENSIONS[$mime] ?? 'bin';

        $path = Storage::disk('local')->putFileAs('recordings', $file, "{$id}.{$extension}");

        $recording = Recording::create([
            'id' => $id,
            'path' => $path,
            'mime' => $mime,
            'status' => 'pending',
        ]);

        ProcessRecording::dispatch($recording->id);

        return response()->json(['id' => $recording->id], 202);
    }
}

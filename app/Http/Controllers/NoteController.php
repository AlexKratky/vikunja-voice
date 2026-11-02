<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRecording;
use App\Models\Recording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    /**
     * A typed note: stored as a recording without audio, then processed by
     * the same job, so it gets the same retries and notifications.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $recording = Recording::create([
            'text' => $validated['text'],
            'status' => 'pending',
        ]);

        ProcessRecording::dispatch($recording->id);

        return response()->json(['id' => $recording->id], 202);
    }
}

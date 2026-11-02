<?php

namespace App\Console\Commands;

use App\Models\Recording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneRecordings extends Command
{
    protected $signature = 'recordings:prune';

    protected $description = 'Delete audio files of processed recordings older than the retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('voice.retention_days'));

        $recordings = Recording::query()
            ->where('status', 'done')
            ->whereNotNull('path') // typed notes have no audio
            ->whereNull('audio_deleted_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($recordings as $recording) {
            Storage::disk('local')->delete($recording->path);
            $recording->update(['audio_deleted_at' => now()]);
        }

        $this->info("Pruned {$recordings->count()} recording(s).");

        return self::SUCCESS;
    }
}

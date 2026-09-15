<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One recording can now produce several Vikunja tasks. The validated
     * extraction is persisted so a retry never re-runs Gemini, and task ids
     * are recorded one by one so a retry never re-creates an existing task.
     */
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->json('extraction')->nullable()->after('transcript');
            $table->json('vikunja_task_ids')->nullable()->after('extraction');
        });

        DB::table('recordings')
            ->whereNotNull('vikunja_task_id')
            ->select('id', 'vikunja_task_id')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('recordings')->where('id', $row->id)->update([
                        'vikunja_task_ids' => json_encode([(int) $row->vikunja_task_id]),
                    ]);
                }
            });

        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('vikunja_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->unsignedBigInteger('vikunja_task_id')->nullable();
        });

        DB::table('recordings')
            ->whereNotNull('vikunja_task_ids')
            ->select('id', 'vikunja_task_ids')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $ids = json_decode((string) $row->vikunja_task_ids, true) ?: [];
                    DB::table('recordings')->where('id', $row->id)->update([
                        'vikunja_task_id' => $ids[0] ?? null,
                    ]);
                }
            });

        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn(['extraction', 'vikunja_task_ids']);
        });
    }
};

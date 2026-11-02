<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A typed note goes through the same pipeline as a recording, but has
     * text instead of an audio file. Existing rows keep their path and mime.
     */
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('mime')->nullable()->change();
            $table->text('text')->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('text');
        });
    }
};

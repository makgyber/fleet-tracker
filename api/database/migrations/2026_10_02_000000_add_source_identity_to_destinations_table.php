<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the tbss source identity (source_type + source_id) to destinations.
 *
 * Destinations were previously de-duplicated by coordinate, which silently
 * collapsed distinct addresses that shared a coordinate (notably the tbss
 * "could not geocode" fallback point) into a single row — dropping stops on
 * import. The real identity of a stop is its tbss source, so we key on that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('notes');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            // One destination row per tbss source. Nullable pair is allowed for
            // ad-hoc destinations created outside the import (e.g. via the API).
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();

            // Requested order (as added by the operator, before optimization).
            $table->unsignedInteger('requested_order')->default(0);
            // Optimized visiting order produced by the Mapbox Optimization API.
            $table->unsignedInteger('sequence')->nullable();

            // Per-leg metrics: distance/duration to reach THIS stop from the previous one.
            $table->float('leg_distance_m')->nullable();
            $table->float('leg_duration_s')->nullable();
            // Computed ETA to arrive at this stop, refreshed as the trip progresses.
            $table->timestamp('eta')->nullable();

            $table->enum('status', ['pending', 'en_route', 'arrived', 'skipped'])
                ->default('pending');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stops');
    }
};

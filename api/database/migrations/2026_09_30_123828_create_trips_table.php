<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable(); // human-friendly job id
            $table->enum('status', ['planned', 'optimized', 'in_progress', 'completed', 'cancelled'])
                ->default('planned');

            // Optional explicit start point. If null, the trip's optimized route
            // is anchored to the vehicle's current live position at planning time.
            $table->decimal('origin_latitude', 10, 7)->nullable();
            $table->decimal('origin_longitude', 10, 7)->nullable();

            // Cached route summary from the last Mapbox optimization run.
            $table->float('total_distance_m')->nullable();   // meters
            $table->float('total_duration_s')->nullable();   // seconds
            $table->longText('route_geometry')->nullable();   // encoded polyline / GeoJSON
            $table->timestamp('route_computed_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};

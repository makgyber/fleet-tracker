<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('label');                 // e.g. "Truck 12"
            $table->string('registration')->unique(); // license plate
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            // Currently assigned driver (a vehicle has at most one active driver).
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['available', 'on_trip', 'maintenance', 'offline'])
                ->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

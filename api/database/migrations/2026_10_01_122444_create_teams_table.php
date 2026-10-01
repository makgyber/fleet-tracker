<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            // Stable identifier from tbss (backs the QR code the driver scans).
            $table->uuid('team_uuid')->unique();
            $table->string('code');                       // tbss team code (per-day)
            $table->date('schedule_date');                // the field-schedule day
            $table->unsignedBigInteger('tbss_schedule_id')->nullable();
            $table->string('color')->nullable();
            $table->string('vehicle_hint')->nullable();   // tbss "vehicle" string, if any
            // Snapshot of member technicians at import time (name list).
            $table->json('members')->nullable();
            // Which fleet vehicle is actually assigned (set in the dashboard).
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_date', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

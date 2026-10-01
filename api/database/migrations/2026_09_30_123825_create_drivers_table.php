<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            // Links a driver to a login (dashboard/app auth). Nullable so a
            // driver record can exist before an account is provisioned.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Firebase Auth UID: ties this driver to the identity that writes
            // GPS to the Realtime Database.
            $table->string('firebase_uid')->nullable()->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('license_number')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};

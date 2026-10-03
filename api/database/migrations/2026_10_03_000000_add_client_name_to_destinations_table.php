<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            // Human-facing client/customer the stop belongs to, surfaced in the
            // dashboard destination popups. Nullable: ad-hoc stops may have none.
            $table->string('client_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('client_name');
        });
    }
};

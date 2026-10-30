<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8 (design approved 2026-10-25): nullable, additive, no backfill. Copied
 * forward from Complaint::tenancy_id only when a task is created from a
 * complaint (Maintenance\Index::convertToTask()) — NULL when a landlord
 * logs a task directly (Maintenance\Index::createTask()), which is
 * correctly property-level with no tenant involved at all, never guessed
 * from house_id/created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->foreignId('tenancy_id')->nullable()->after('house_id')->constrained('tenancies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenancy_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8 (design approved 2026-10-25): nullable, additive, no backfill. This is
 * a forward-only capture — Tenant\Maintenance\Create::submit() already
 * resolves and loads the specific Tenancy before Complaint::create(), it
 * just never persisted it. Every existing row stays NULL forever: that's
 * the honest state for history that was never captured with it, never
 * reconstructed from house_id/tenant_id/created_at guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('tenancy_id')->nullable()->after('house_id')->constrained('tenancies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenancy_id');
        });
    }
};

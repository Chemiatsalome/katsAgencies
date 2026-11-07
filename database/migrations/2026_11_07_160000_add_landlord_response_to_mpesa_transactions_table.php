<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord-to-admin-to-tenant investigation workflow (follow-up to Phase 4
 * Finding 4): a landlord notified that a tenant's M-Pesa payment is stuck
 * needs to say whether the money actually arrived, without that
 * acknowledgement itself crediting anything or resolving the transaction —
 * mirrors cancellation_requested_at/by's exact shape (the tenant's own
 * equivalent "I've flagged this" metadata), kept fully separate from
 * investigated_at/by/outcome (admin/system resolution authority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // 'received' | 'not_received' — the landlord's own answer to
            // "did this money actually arrive on your side?", never a
            // status value and never read as a resolution on its own.
            $table->string('landlord_response')->nullable()->after('investigation_reference');
            $table->timestamp('landlord_response_at')->nullable()->after('landlord_response');
            $table->foreignId('landlord_response_by')->nullable()->after('landlord_response_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landlord_response_by');
            $table->dropColumn(['landlord_response', 'landlord_response_at']);
        });
    }
};

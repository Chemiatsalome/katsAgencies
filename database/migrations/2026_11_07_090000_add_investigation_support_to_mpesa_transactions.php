<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 audit, Finding 4: supports recovering/investigating a stuck M-Pesa
 * transaction without ever introducing a new `status` value or rewriting a
 * historical record. `status` keeps meaning exactly what it means today
 * ('pending' = still unresolved); every new column here is purely
 * investigation metadata layered on top of it. The 5 admin-visible states
 * required by the design are represented by combinations of `status` with
 * `cancellation_requested_at` / `investigation_outcome`, not a new enum
 * value — see MpesaInvestigationService's docblock for the mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->timestamp('last_queried_at')->nullable()->after('raw_callback');
            $table->timestamp('cancellation_requested_at')->nullable()->after('last_queried_at');
            $table->foreignId('cancellation_requested_by')->nullable()->after('cancellation_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('investigated_at')->nullable()->after('cancellation_requested_by');
            $table->foreignId('investigated_by')->nullable()->after('investigated_at')->constrained('users')->nullOnDelete();
            // Null = no investigation outcome recorded at all (either still
            // awaiting a provider response, or resolved straight from a real
            // callback/query with no admin step involved).
            $table->string('investigation_outcome')->nullable()->after('investigated_by');
            $table->text('investigation_notes')->nullable()->after('investigation_outcome');
            // The receipt/reference an admin cited when linking this
            // transaction to an already-recorded Payment/DepositPayment via
            // resolveAsSuccessful() — distinct from mpesa_receipt_number,
            // which only a real Safaricom callback/query ever sets.
            $table->string('investigation_reference')->nullable()->after('investigation_notes');
        });

        // History of every provider status-query attempt against a
        // transaction — distinct from the transaction's own single
        // last_queried_at timestamp, which only ever shows the most recent
        // attempt. Cascades with its parent transaction, matching that
        // row's own existing deletion semantics (mpesa_transactions.tenancy_id
        // already cascades from Tenancy, which already cascades from Tenant
        // — see Tenants\Index::delete()): this table is never more
        // protected against deletion than the record it documents.
        Schema::create('mpesa_status_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mpesa_transaction_id')->constrained('mpesa_transactions')->cascadeOnDelete();
            // 'reconciliation' (automated cron) | 'tenant_cancellation' (a
            // tenant's cancellation request triggering an immediate query) |
            // 'admin_requery' (an admin manually asking for a fresh query).
            $table->string('source');
            // Null for the automated reconciliation cron; the acting user
            // for a tenant- or admin-triggered query.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            // Sanitised before storage (see MpesaInvestigationService::sanitizeProviderResponse())
            // — never contains a password, passkey, access token or Authorization header.
            $table->json('raw_response')->nullable();
            // How this query's result was acted on: 'cancellation_applied' |
            // 'escalated_manual_verification' | 'sms_topup_credited' | 'inconclusive'.
            $table->string('interpreted_as')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_status_queries');

        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investigated_by');
            $table->dropConstrainedForeignId('cancellation_requested_by');
            $table->dropColumn([
                'last_queried_at', 'cancellation_requested_at', 'investigated_at',
                'investigation_outcome', 'investigation_notes', 'investigation_reference',
            ]);
        });
    }
};

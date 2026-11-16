<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (account lifecycle), point C: an admin cannot suspend or
 * deactivate a manager directly — this is the smallest functional
 * record of "an admin flagged this manager to the responsible landlord,
 * who decides what to do about it." Deliberately no status/workflow
 * machinery beyond that; the landlord's actual decision is whatever they
 * already do through Managers\Index (deactivate/remove), unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_reports', function (Blueprint $table) {
            $table->id();
            // nullable + nullOnDelete, not cascade: removing the Manager
            // delegation row (the normal, intended outcome of a landlord
            // acting on a report) must not destroy the audit record of why
            // they acted — only the now-dangling reference goes.
            $table->foreignId('manager_id')->nullable()->constrained('managers')->nullOnDelete();
            // restrictOnDelete, matching the F8 precedent for audit/ledger
            // rows (disbursements.landlord_id) — this is an audit record,
            // never silently destroyed by deleting the User it names.
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_reports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_notice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_notice_id')->constrained('tenancy_notices')->cascadeOnDelete();
            $table->enum('event_type', [
                'issued', 'acknowledged', 'dispute', 'request_time', 'request_extension',
                'decision_approve_time', 'decision_approve_extension', 'decision_decline',
                'withdrawn', 'superseded', 'closed',
            ]);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('caused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->date('new_vacate_date')->nullable();
            $table->timestamps();

            $table->index(['tenancy_notice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_notice_events');
    }
};

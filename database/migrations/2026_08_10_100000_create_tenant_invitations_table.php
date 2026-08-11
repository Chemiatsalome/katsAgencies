<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('existing_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('tenant_phone')->nullable();
            $table->string('tenant_email')->nullable();
            $table->decimal('monthly_rent', 10, 2);
            $table->decimal('deposit_required', 10, 2)->default(0);
            $table->unsignedTinyInteger('rent_due_day');
            $table->date('move_in_date');
            $table->date('lease_end_date')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->enum('status', ['pending', 'opened', 'completed', 'cancelled', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('resulting_tenancy_id')->nullable()->constrained('tenancies')->nullOnDelete();
            $table->timestamps();

            $table->index(['house_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};

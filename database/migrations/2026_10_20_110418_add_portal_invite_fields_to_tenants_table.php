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
        Schema::table('tenants', function (Blueprint $table) {
            // Lets a landlord give portal access to a tenant who already has
            // an active tenancy (e.g. imported from a spreadsheet) without
            // going through the TenantInvitation flow, which always creates
            // a brand-new tenancy and requires the unit to be vacant.
            $table->string('portal_invite_token')->nullable()->unique()->after('user_id');
            $table->timestamp('portal_invite_sent_at')->nullable()->after('portal_invite_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['portal_invite_token', 'portal_invite_sent_at']);
        });
    }
};

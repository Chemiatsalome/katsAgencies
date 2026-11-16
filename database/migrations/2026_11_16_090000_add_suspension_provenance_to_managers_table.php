<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (account lifecycle): distinguishes a manager made inactive as a
 * side effect of their landlord being suspended from one the landlord
 * independently deactivated themselves — reinstating the landlord must
 * restore only the former. Set when the landlord-suspension cascade
 * deactivates an active manager, cleared the moment that manager is
 * restored; a manager the landlord deactivates directly never has it set
 * at all, so reinstatement leaves them alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managers', function (Blueprint $table) {
            $table->timestamp('suspended_via_landlord_suspension_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('managers', function (Blueprint $table) {
            $table->dropColumn('suspended_via_landlord_suspension_at');
        });
    }
};

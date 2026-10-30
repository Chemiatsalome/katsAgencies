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
        Schema::table('managers', function (Blueprint $table) {
            // Staged permission grants, chosen by the landlord at invite/edit
            // time. Applied to the manager's actual User via syncPermissions()
            // once they accept (no User row exists until then) — kept here
            // afterwards too so edits to an active manager have something to
            // diff against and the invitation UI can redisplay past choices.
            $table->json('permissions')->nullable()->after('all_properties');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('managers', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};

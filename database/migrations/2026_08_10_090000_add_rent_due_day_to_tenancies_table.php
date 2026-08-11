<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->unsignedTinyInteger('rent_due_day')->nullable()->after('deposit_paid');
        });

        // Backfill from the tenant's default due day. Written as a portable
        // read-then-update (rather than a cross-table UPDATE...JOIN) so it
        // also works against SQLite, which the test suite runs on.
        $rows = DB::table('tenancies')
            ->join('tenants', 'tenants.id', '=', 'tenancies.tenant_id')
            ->whereNull('tenancies.rent_due_day')
            ->select('tenancies.id as tenancy_id', 'tenants.payment_due_day')
            ->get();

        foreach ($rows as $row) {
            DB::table('tenancies')
                ->where('id', $row->tenancy_id)
                ->update(['rent_due_day' => $row->payment_due_day]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropColumn('rent_due_day');
        });
    }
};

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
        Schema::table('users', function (Blueprint $table) {
            // Null means "never approved" — the only way to tell a landlord
            // signup still awaiting its first review apart from one that was
            // active before and has since been deactivated/suspended, since
            // both look identical as status=inactive otherwise.
            $table->timestamp('approved_at')->nullable()->after('status');
        });

        // Backfill: every account that isn't currently a fresh pending
        // signup (i.e. already active, or previously active and since
        // suspended/deactivated) has necessarily been approved before.
        DB::table('users')->where('status', '!=', 'inactive')->update(['approved_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};

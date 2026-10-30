<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Before this change, confirming a resolved complaint only stamped
     * tenant_confirmed_at and left status at 'resolved' forever — nothing
     * ever moved it to 'closed'. Any complaint confirmed under that old
     * behavior needs to be caught up now, otherwise it stays invisible to
     * the new status === 'closed' checks (the reply-blocking guard, the
     * History tab's old query) despite genuinely being done.
     */
    public function up(): void
    {
        DB::table('complaints')
            ->where('status', 'resolved')
            ->whereNotNull('tenant_confirmed_at')
            ->update(['status' => 'closed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately not reversed — there's no reliable way to tell a
        // complaint this migration closed apart from one that was closed
        // some other way afterward, and reopening either would be wrong.
    }
};

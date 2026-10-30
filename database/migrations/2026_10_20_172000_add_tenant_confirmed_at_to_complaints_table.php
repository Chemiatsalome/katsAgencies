<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            // Set only once the tenant explicitly confirms the work was
            // actually done — landlord marking a task "completed" alone
            // (which flips this complaint to "resolved") never implied the
            // tenant agreed; this is that missing confirmation step.
            $table->timestamp('tenant_confirmed_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('tenant_confirmed_at');
        });
    }
};

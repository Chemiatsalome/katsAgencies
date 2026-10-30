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
        Schema::table('complaints', function (Blueprint $table) {
            // A tenant reporting that a closed request's issue has come
            // back files a brand-new complaint rather than reopening the
            // closed one — this links the two without touching the closed
            // request's own history.
            $table->foreignId('related_complaint_id')->nullable()->after('tenant_confirmed_at')
                ->constrained('complaints')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_complaint_id');
        });
    }
};

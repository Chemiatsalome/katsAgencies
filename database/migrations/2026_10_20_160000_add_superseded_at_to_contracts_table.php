<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Set the moment a newer version of this tenancy's contract is
            // generated/uploaded — kept separate from `status` so a
            // superseded contract's own signing history (status, signed_by_name,
            // signed_at) stays intact for audit purposes instead of being
            // overwritten.
            $table->timestamp('superseded_at')->nullable()->after('declined_reason');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_templates', function (Blueprint $table) {
            // Null means "fallback default for any property that doesn't
            // have its own" — a landlord can draft one agreement per
            // property/apartment type instead of being limited to a single
            // one-size-fits-all default for their whole portfolio.
            $table->foreignId('property_id')->nullable()->after('landlord_id')->constrained('properties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};

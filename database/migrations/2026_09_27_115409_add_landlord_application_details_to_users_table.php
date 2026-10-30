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
        Schema::table('users', function (Blueprint $table) {
            // Collected at landlord self-registration only, as a basis for
            // the admin approval decision on the Applications review page —
            // irrelevant for tenants/managers/admins, who never self-register.
            $table->string('national_id')->nullable()->after('phone');
            $table->string('business_name')->nullable()->after('national_id');
            $table->string('property_location')->nullable()->after('business_name');
            $table->unsignedInteger('intended_unit_count')->nullable()->after('property_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['national_id', 'business_name', 'property_location', 'intended_unit_count']);
        });
    }
};

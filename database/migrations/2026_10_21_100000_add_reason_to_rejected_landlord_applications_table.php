<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rejected_landlord_applications', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('intended_unit_count');
        });
    }

    public function down(): void
    {
        Schema::table('rejected_landlord_applications', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};

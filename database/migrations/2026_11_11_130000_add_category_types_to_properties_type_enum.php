<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Same reasoning as add_villas_to_properties_type_enum — SQLite bakes
            // its enum in as a CHECK constraint at CREATE TABLE time with no
            // ALTER COLUMN support, so widen to a plain string instead.
            Schema::table('properties', function (Blueprint $table) {
                $table->string('type')->default('other')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE properties MODIFY type ENUM('apartment', 'flats', 'residential_compound', 'individual_house', 'maisonette', 'commercial_building', 'shopping_mall', 'office_building', 'mixed_use', 'villas', 'other') NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        DB::table('properties')->where('type', 'flats')->update(['type' => 'apartment']);
        DB::table('properties')->where('type', 'shopping_mall')->update(['type' => 'commercial_building']);
        DB::table('properties')->where('type', 'office_building')->update(['type' => 'other']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE properties MODIFY type ENUM('apartment', 'residential_compound', 'individual_house', 'maisonette', 'commercial_building', 'mixed_use', 'villas', 'other') NOT NULL DEFAULT 'other'"
        );
    }
};

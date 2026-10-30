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
            // Same reasoning as add_villa_to_houses_type_enum — SQLite bakes
            // its enum in as a CHECK constraint at CREATE TABLE time with no
            // ALTER COLUMN support, so widen to a plain string instead.
            Schema::table('properties', function (Blueprint $table) {
                $table->string('type')->default('other')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE properties MODIFY type ENUM('apartment', 'residential_compound', 'individual_house', 'maisonette', 'commercial_building', 'mixed_use', 'villas', 'other') NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        DB::table('properties')->where('type', 'villas')->update(['type' => 'other']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE properties MODIFY type ENUM('apartment', 'residential_compound', 'individual_house', 'maisonette', 'commercial_building', 'mixed_use', 'other') NOT NULL DEFAULT 'other'"
        );
    }
};

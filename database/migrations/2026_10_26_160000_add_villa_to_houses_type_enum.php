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
            // SQLite has no ALTER COLUMN; its enum is emulated as a CHECK
            // constraint baked in at CREATE TABLE time. Drop the constraint
            // by widening the column to a plain string instead of rebuilding
            // the whole table — validation of allowed values already happens
            // at the application layer (House::TYPES / Houses\Form, Properties\Wizard).
            Schema::table('houses', function (Blueprint $table) {
                $table->string('type')->default('other')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE houses MODIFY type ENUM('bedsitter', 'studio', 'sq', 'one_bedroom', 'two_bedroom', 'three_bedroom', 'four_bedroom', 'house', 'maisonette', 'shop', 'office', 'villa', 'other') NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        DB::table('houses')->where('type', 'villa')->update(['type' => 'house']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE houses MODIFY type ENUM('bedsitter', 'studio', 'sq', 'one_bedroom', 'two_bedroom', 'three_bedroom', 'four_bedroom', 'house', 'maisonette', 'shop', 'office', 'other') NOT NULL DEFAULT 'other'"
        );
    }
};

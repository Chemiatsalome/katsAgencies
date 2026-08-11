<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no ALTER COLUMN; its enum is emulated as a CHECK
            // constraint baked in at CREATE TABLE time. Drop the constraint
            // by widening the column to a plain string instead of rebuilding
            // the whole table — validation of allowed values already happens
            // at the application layer (RentCharge::recalculateStatus()).
            Schema::table('rent_charges', function (Blueprint $table) {
                $table->string('status')->default('unpaid')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE rent_charges MODIFY status ENUM('unpaid', 'partial', 'paid', 'overdue', 'advance') NOT NULL DEFAULT 'unpaid'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rent_charges')->where('status', 'advance')->update(['status' => 'paid']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE rent_charges MODIFY status ENUM('unpaid', 'partial', 'paid', 'overdue') NOT NULL DEFAULT 'unpaid'"
        );
    }
};

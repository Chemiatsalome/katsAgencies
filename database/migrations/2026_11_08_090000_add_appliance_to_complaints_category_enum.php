<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A, F2: Tenant\Maintenance\Create::CATEGORIES already offered
     * 'appliance' as a selectable option, but the complaints.category enum
     * never included it — selecting it threw a data-truncation error under
     * MySQL strict mode. Mirrors the existing enum-widening pattern used for
     * houses.type (see add_villa_to_houses_type_enum.php): SQLite's enum is
     * only a CHECK constraint baked in at CREATE TABLE time with no ALTER
     * COLUMN support, so it's widened to a plain string there (validation
     * already happens at the application layer via Create::CATEGORIES);
     * MySQL gets the real enum altered in place. Existing rows are untouched
     * either way.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('complaints', function (Blueprint $table) {
                $table->string('category')->default('other')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE complaints MODIFY category ENUM('plumbing', 'electrical', 'security', 'water', 'structural', 'cleaning', 'appliance', 'other') NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        DB::table('complaints')->where('category', 'appliance')->update(['category' => 'other']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE complaints MODIFY category ENUM('plumbing', 'electrical', 'security', 'water', 'structural', 'cleaning', 'other') NOT NULL DEFAULT 'other'"
        );
    }
};

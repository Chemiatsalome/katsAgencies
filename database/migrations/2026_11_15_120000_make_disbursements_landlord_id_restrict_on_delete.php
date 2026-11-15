<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 (account lifecycle audit): disbursements.landlord_id had no explicit
 * onDelete() clause at all — the database already blocked a landlord
 * delete through its own implicit default (RESTRICT under MySQL/InnoDB,
 * and under SQLite with foreign_key_constraints enabled, as this project's
 * test database runs with), but that was never a stated, intentional rule,
 * just an accident of what `constrained()` falls back to when no clause is
 * given. No code anywhere deletes a landlord's User record today — there is
 * no landlord-deletion feature, and F1 removed the one generic path that
 * could have reached it for any role — so making this explicit changes
 * nothing about current behaviour; it only documents and locks in the rule
 * so it can't be lost if the implicit default is ever assumed away by a
 * future schema change. recorded_by is left untouched: it is this finding's
 * out of scope, unflagged by the audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropForeign(['landlord_id']);
            $table->foreign('landlord_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropForeign(['landlord_id']);
            $table->foreign('landlord_id')->references('id')->on('users');
        });
    }
};

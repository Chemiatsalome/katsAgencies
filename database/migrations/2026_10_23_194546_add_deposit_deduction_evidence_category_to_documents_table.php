<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Switches the DB-level enum to a plain string — the enum required a
     * schema migration every time a new document category was introduced
     * (as this one is), when App\Livewire\Documents\Manager::CATEGORIES is
     * already the single source of truth the application validates against.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('category')->default('other')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('category', [
                'lease_agreement', 'id_document', 'property_document', 'receipt',
                'payment_confirmation', 'maintenance_invoice', 'inspection_report',
                'landlord_document', 'other',
            ])->default('other')->change();
        });
    }
};

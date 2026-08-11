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
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->morphs('notifiable');
            $table->enum('channel', ['in_app', 'email', 'sms'])->default('in_app');
            $table->string('event_type');
            $table->string('title');
            $table->text('body');
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed'])->default('pending');
            $table->nullableMorphs('related');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};

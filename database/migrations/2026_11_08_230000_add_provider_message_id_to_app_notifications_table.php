<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * G-1B: captures SentMessage::getMessageId() when email submission
     * succeeds — whatever Symfony's SMTP transport ends up setting, which
     * may be a provider-assigned id (if Resend's final "250" response
     * matches the Postfix-style "queued as <id>"/"id=<id>" convention
     * Symfony's SmtpTransport::parseMessageId() looks for) or, failing
     * that, the self-generated RFC Message-ID header. Channel-agnostic
     * column name and nullable, since in_app/sms rows never populate it
     * and a successful send with no parseable id is still a success.
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('submission_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('provider_message_id');
        });
    }
};

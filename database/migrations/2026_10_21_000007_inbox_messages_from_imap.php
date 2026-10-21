<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox is now filled by IMAP polling (`inbox:poll`) instead of the Hostinger Agentic Mail
 * webhook, which never fired for mail sent to mailbox aliases. Drop the webhook-only columns;
 * `event_id` now holds the IMAP source id (imap:<uidvalidity>:<uid>).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn(['delivery_id', 'html_preview', 'body_url', 'body_fetched_at']);
            $table->string('provider', 20)->default('imap')->change();
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->string('provider', 20)->default('hostinger_mail')->change();
            $table->string('delivery_id')->nullable()->index()->after('event_id');
            $table->text('html_preview')->nullable()->after('plain_preview');
            $table->text('body_url')->nullable()->after('html_body');
            $table->timestamp('body_fetched_at')->nullable()->after('body_url');
        });
    }
};

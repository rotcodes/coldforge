<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('hostinger_mail')->index();
            $table->string('event_id')->unique(); // dedup key from Hostinger payload (data.eventId)
            $table->string('delivery_id')->nullable()->index();
            $table->string('mailbox_address')->index();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->json('references')->nullable();
            $table->string('from_email')->index();
            $table->string('from_name')->nullable();
            $table->json('to_addresses')->nullable();
            $table->json('cc_addresses')->nullable();
            $table->string('subject')->nullable();
            $table->text('plain_preview')->nullable();  // max 200 chars from webhook
            $table->text('html_preview')->nullable();
            $table->string('body_url', 500)->nullable();  // signed URL to fetch full body
            $table->json('attachments')->nullable();
            $table->foreignId('campaign_send_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('classification', 20)->nullable()->index();  // interested, needs_info, not_interested, oof, wrong_person, unsub, spam
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('received_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};

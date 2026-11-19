<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_settings', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->text('password')->nullable();
            $table->string('smtp_host')->default('smtp.hostinger.com');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 10)->default('ssl');
            $table->string('imap_host')->default('imap.hostinger.com');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl');
            $table->unsignedInteger('daily_allowance')->default(100);
            $table->unsignedInteger('sent_today')->default(0);
            $table->date('allowance_reset_at')->nullable();
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('smtp_status', 20)->default('unconfigured');
            $table->string('imap_status', 20)->default('unconfigured');
            $table->timestamp('last_smtp_test_at')->nullable();
            $table->timestamp('last_imap_test_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_connection_error')->nullable();
            $table->longText('signature_html')->nullable();
            $table->text('signature_text')->nullable();
            $table->boolean('automation_paused')->default(false);
            $table->boolean('track_campaign_opens')->default(true);
            $table->boolean('track_campaign_clicks')->default(true);
            $table->timestamps();
        });

        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('name');
            $table->string('delimiter', 5)->nullable();
            $table->string('special_use', 30)->nullable()->index();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('uid_next')->nullable();
            $table->unsignedBigInteger('last_synced_uid')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique('path');
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_send_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('remote_uid')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->json('references')->nullable();
            $table->string('thread_key')->nullable()->index();
            $table->string('direction', 10)->default('incoming')->index();
            $table->string('status', 20)->default('synced')->index();
            $table->string('from_email')->nullable()->index();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->longText('plain_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('flags')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('is_starred')->default(false)->index();
            $table->unsignedInteger('attachment_count')->default(0);
            $table->timestamp('message_at')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('send_attempts')->default(0);
            $table->text('send_error')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->unique(['mail_folder_id', 'remote_uid']);
        });

        Schema::create('mail_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5); // to, cc, bcc
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->index(['mail_message_id', 'type']);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id')->nullable();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disposition', 20)->nullable();
            $table->string('content_id')->nullable();
            $table->string('storage_path')->nullable();
            $table->boolean('is_downloaded')->default(false);
            $table->timestamps();
        });

        Schema::create('mail_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->index();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 40)->index();
            $table->string('source', 40)->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('suppressed_at');
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('pipeline_status', 30)->default('new')->index()->after('status');
            $table->string('permission_status', 30)->default('unknown')->index()->after('pipeline_status');
            $table->string('consent_source')->nullable()->after('permission_status');
            $table->text('consent_evidence')->nullable()->after('consent_source');
            $table->text('notes')->nullable()->after('consent_evidence');
            $table->timestamp('last_contacted_at')->nullable()->index()->after('notes');
            $table->timestamp('last_replied_at')->nullable()->index()->after('last_contacted_at');
            $table->timestamp('next_interaction_at')->nullable()->index()->after('last_replied_at');
            $table->text('ai_summary')->nullable()->after('next_interaction_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'pipeline_status', 'permission_status', 'consent_source', 'consent_evidence', 'notes',
                'last_contacted_at', 'last_replied_at', 'next_interaction_at', 'ai_summary',
            ]);
        });

        foreach (['activity_logs', 'suppressions', 'mail_sync_logs', 'mail_attachments', 'mail_recipients', 'mail_messages', 'mail_folders', 'mailbox_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

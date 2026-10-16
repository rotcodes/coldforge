<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('country')->nullable();
            $table->json('custom_fields')->nullable();
            $table->string('status', 20)->default('active')->index(); // active, bounced, unsubscribed, complained
            $table->string('source')->default('manual');
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('csv_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path');
            $table->string('status', 20)->default('pending'); // pending, processing, done, failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('mapping')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('from_name')->nullable();
            $table->string('from_email');
            $table->string('reply_to')->nullable();
            $table->longText('html_body');
            $table->longText('text_body')->nullable();
            $table->json('variables')->nullable();
            $table->timestamps();
        });

        Schema::create('senders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 20); // brevo, mailersend, elastic, mailjet, smtp_generic
            $table->text('config'); // encrypted json
            $table->unsignedInteger('daily_quota')->default(300);
            $table->unsignedInteger('sent_today')->default(0);
            $table->date('reset_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(1);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->string('sender_strategy', 20)->default('round_robin'); // round_robin, priority
            $table->json('contact_filter')->nullable();
            $table->timestamp('schedule_at')->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft, scheduled, running, paused, completed, failed
            $table->unsignedInteger('daily_cap')->default(50);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued')->index(); // queued, sending, sent, failed, bounced
            $table->string('message_id')->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->unsignedInteger('clicked_count')->default(0);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_send_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->index(); // queued, sent, failed, bounced, opened, clicked, unsubscribed, complained
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'events', 'campaign_sends', 'campaigns', 'senders', 'templates', 'csv_imports', 'contacts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

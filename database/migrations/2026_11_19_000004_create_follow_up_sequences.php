<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('business_days_only')->default(true);
            $table->time('window_start')->nullable();
            $table->time('window_end')->nullable();
            $table->string('timezone')->default('Asia/Karachi');
            $table->timestamps();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('delay_days')->default(2);
            $table->timestamps();
            $table->unique(['sequence_id', 'position']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('sequence_id')->nullable()->after('template_id')->constrained()->nullOnDelete();
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_send_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedSmallInteger('next_step_position')->default(1);
            $table->timestamp('next_send_at')->nullable()->index();
            $table->string('stop_reason')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->unique(['sequence_id', 'campaign_send_id']);
        });

        Schema::create('sequence_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['sequence_enrollment_id', 'sequence_step_id'], 'seq_delivery_enrollment_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_deliveries');
        Schema::dropIfExists('sequence_enrollments');
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropConstrainedForeignId('sequence_id'));
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
    }
};

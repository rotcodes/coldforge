<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 60)->index();
            $table->string('model');
            $table->string('prompt_version', 40);
            $table->string('status', 20)->index();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->default('ColdForge Intelligence');
            $table->timestamps();
        });

        Schema::create('ai_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();
            $table->timestamps();
            $table->index(['ai_conversation_id', 'id']);
        });

        Schema::table('ai_classifications', function (Blueprint $table) {
            $table->decimal('confidence', 5, 4)->nullable()->after('urgency');
            $table->text('reasoning')->nullable()->after('summary');
            $table->boolean('manually_corrected')->default(false)->after('reasoning');
        });

        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->string('scan_status', 20)->default('pending')->index()->after('is_downloaded');
            $table->timestamp('scanned_at')->nullable()->after('scan_status');
            $table->text('scan_result')->nullable()->after('scanned_at');
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('delivery_event', 30)->nullable()->index()->after('send_error');
            $table->json('delivery_details')->nullable()->after('delivery_event');
            $table->timestamp('delivery_processed_at')->nullable()->index()->after('delivery_details');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', fn (Blueprint $table) => $table->dropColumn(['delivery_event', 'delivery_details', 'delivery_processed_at']));
        Schema::table('mail_attachments', fn (Blueprint $table) => $table->dropColumn(['scan_status', 'scanned_at', 'scan_result']));
        Schema::table('ai_classifications', fn (Blueprint $table) => $table->dropColumn(['confidence', 'reasoning', 'manually_corrected']));
        Schema::dropIfExists('ai_conversation_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_requests');
    }
};

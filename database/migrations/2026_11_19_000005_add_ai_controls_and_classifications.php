<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_settings', function (Blueprint $table) {
            $table->boolean('ai_processing_enabled')->default(false)->after('track_campaign_clicks');
            $table->string('ai_model')->default('gpt-6.2-sol')->after('ai_processing_enabled');
        });

        Schema::create('ai_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained()->cascadeOnDelete();
            $table->string('intent', 40)->nullable()->index();
            $table->string('sentiment', 30)->nullable();
            $table->string('urgency', 20)->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->string('model');
            $table->timestamps();
            $table->unique('mail_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_classifications');
        Schema::table('mailbox_settings', fn (Blueprint $table) => $table->dropColumn(['ai_processing_enabled', 'ai_model']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('brevo')->index();
            $table->string('event', 32)->index();
            $table->string('email')->nullable()->index();
            $table->string('message_id')->nullable()->index();
            $table->foreignId('campaign_send_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('link')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('mode', 20)->default('read_only')->after('title');
        });

        Schema::create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('risk', 20)->index();
            $table->string('status', 20)->default('proposed')->index();
            $table->string('summary');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        DB::table('mailbox_settings')->whereIn('ai_model', ['openai/gpt-oss-120b', 'openai/gpt-oss-20b'])->update(['ai_model' => 'gpt-6.2-sol']);
        Schema::table('mailbox_settings', fn (Blueprint $table) => $table->string('ai_model')->default('gpt-6.2-sol')->change());
    }

    public function down(): void
    {
        DB::table('mailbox_settings')->whereIn('ai_model', ['gpt-6.2-sol', 'gpt-6.2-astra', 'gpt-6.1-terra'])->update(['ai_model' => 'openai/gpt-oss-120b']);
        Schema::table('mailbox_settings', fn (Blueprint $table) => $table->string('ai_model')->default('openai/gpt-oss-120b')->change());
        Schema::dropIfExists('ai_actions');
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};

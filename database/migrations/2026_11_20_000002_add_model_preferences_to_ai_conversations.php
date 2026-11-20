<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('model')->default('gpt-6.2-sol')->after('mode');
            $table->string('reasoning_effort', 20)->default('medium')->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->dropColumn(['model', 'reasoning_effort']));
    }
};

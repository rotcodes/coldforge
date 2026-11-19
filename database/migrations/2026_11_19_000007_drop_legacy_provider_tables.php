<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('webhook_events');
        if (Schema::hasColumn('campaign_sends', 'sender_id')) {
            Schema::table('campaign_sends', function (Blueprint $table) {
                $table->dropConstrainedForeignId('sender_id');
            });
        }
        Schema::dropIfExists('senders');
    }

    public function down(): void
    {
        // Legacy provider tables are intentionally not recreated.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a `replied` status for Contact (new terminal "do-not-pitch" state meaning
 * the lead already engaged — follow-ups shouldn't go back out automatically),
 * and a `replied_count` counter per Campaign fed by the inbox webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('replied_count')->default(0)->after('unsubscribed_count');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('replied_count');
        });
    }
};

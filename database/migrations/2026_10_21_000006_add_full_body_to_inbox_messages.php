<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Full message body copied from Hostinger's expiring bodyUrl (see FetchInboxBodyJob). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->longText('plain_body')->nullable()->after('html_preview');
            $table->longText('html_body')->nullable()->after('plain_body');
            $table->timestamp('body_fetched_at')->nullable()->after('body_url');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn(['plain_body', 'html_body', 'body_fetched_at']);
        });
    }
};

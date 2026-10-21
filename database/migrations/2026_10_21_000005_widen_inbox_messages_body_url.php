<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostinger's bodyUrl is a pre-signed download link well over 500 chars, so
 * every real message.received webhook 500'd on insert. Subjects can also run
 * past 255 on long "Re: Re: ..." threads. Both become TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->text('body_url')->nullable()->change();
            $table->text('subject')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->string('body_url', 500)->nullable()->change();
            $table->string('subject')->nullable()->change();
        });
    }
};

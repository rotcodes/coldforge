<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_settings', function (Blueprint $table) {
            $table->string('physical_address')->nullable()->after('signature_text');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_settings', function (Blueprint $table) {
            $table->dropColumn('physical_address');
        });
    }
};

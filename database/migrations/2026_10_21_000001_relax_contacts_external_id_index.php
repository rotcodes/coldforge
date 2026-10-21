<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downgrade the (source_system, external_id) UNIQUE to a plain index.
 *
 * Rationale: `email` is already unique on contacts, so cross-import dedup is
 * covered. The composite unique was blocking legitimate CSVs where the same
 * CRM lead id gets re-emitted under a different email (common when a lead's
 * contact person changes mid-pipeline). The composite is still useful as a
 * query index for filters like "all leads from source=CRM with id=X".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_source_external_unique');
            $table->index(['source_system', 'external_id'], 'contacts_source_external_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_source_external_index');
            $table->unique(['source_system', 'external_id'], 'contacts_source_external_unique');
        });
    }
};

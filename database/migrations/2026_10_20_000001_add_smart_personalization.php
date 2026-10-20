<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('label');
            $table->text('workflows');
            $table->text('tracking');
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('business')->nullable()->after('company');
            $table->string('industry')->nullable()->index()->after('business');
            $table->string('industry_slug', 60)->nullable()->index()->after('industry');
            $table->string('city_state')->nullable()->after('country');
            $table->string('website')->nullable()->after('city_state');
            $table->string('decision_maker')->nullable()->after('website');
            $table->string('dm_role')->nullable()->after('decision_maker');
            $table->string('direct_phone', 50)->nullable()->after('dm_role');
            $table->string('main_phone', 50)->nullable()->after('direct_phone');
            $table->string('company_linkedin', 500)->nullable()->after('main_phone');
            $table->string('dm_linkedin', 500)->nullable()->after('company_linkedin');
            $table->string('source_system', 20)->nullable()->index()->after('source');
            $table->string('external_id', 100)->nullable()->after('source_system');
            $table->string('remote_status', 50)->nullable()->index()->after('external_id');
            $table->string('remote_reply_status', 50)->nullable()->after('remote_status');
            $table->smallInteger('lead_score')->nullable()->after('remote_reply_status');
            $table->string('confidence', 20)->nullable()->after('lead_score');
            $table->text('personalized_opener')->nullable()->after('confidence');
            $table->text('pain_evidence')->nullable()->after('personalized_opener');
            $table->text('recommended_offer')->nullable()->after('pain_evidence');
            $table->string('custom_subject')->nullable()->after('recommended_offer');
            $table->text('why_now_trigger')->nullable()->after('custom_subject');
            $table->string('notion_url', 500)->nullable()->after('why_now_trigger');
            $table->text('other_emails')->nullable()->after('notion_url');
            $table->unique(['source_system', 'external_id'], 'contacts_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_source_external_unique');
            $table->dropColumn([
                'business', 'industry', 'industry_slug', 'city_state', 'website',
                'decision_maker', 'dm_role', 'direct_phone', 'main_phone',
                'company_linkedin', 'dm_linkedin', 'source_system', 'external_id',
                'remote_status', 'remote_reply_status', 'lead_score', 'confidence',
                'personalized_opener', 'pain_evidence', 'recommended_offer',
                'custom_subject', 'why_now_trigger', 'notion_url', 'other_emails',
            ]);
        });
        Schema::dropIfExists('industries');
    }
};

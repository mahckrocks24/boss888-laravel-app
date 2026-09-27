<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRAND-B1 (RFC-0017 section 5d, Owner 2026-09-27): the brand preferences of ONE business profile.
 * Sarah asks for them in her first interaction; the owner picks 3-4 of the ten design directions and may
 * upload guidelines, a logo, fonts or example posts. Everything is saved per business, with provenance
 * and a version history, and read by every image, banner, caption and video request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_brand_identities', function (Blueprint $t) {
            if (! Schema::hasColumn('creative_brand_identities', 'directions_json')) $t->json('directions_json')->nullable()->after('style_notes');        // {picks:[D1..], never:[..], chosen_at, source}
            if (! Schema::hasColumn('creative_brand_identities', 'rules_json')) $t->json('rules_json')->nullable()->after('directions_json');              // [{rule, source, at}]
            if (! Schema::hasColumn('creative_brand_identities', 'sources_json')) $t->json('sources_json')->nullable()->after('rules_json');               // {field: {source, at}}
            if (! Schema::hasColumn('creative_brand_identities', 'assets_json')) $t->json('assets_json')->nullable()->after('sources_json');               // [{kind: logo|font|example|guideline, media_id, url, name, notes}]
            if (! Schema::hasColumn('creative_brand_identities', 'proposal_json')) $t->json('proposal_json')->nullable()->after('assets_json');            // extracted, waiting for the owner's confirmation
            if (! Schema::hasColumn('creative_brand_identities', 'intake_status')) $t->string('intake_status', 16)->nullable()->after('proposal_json');    // null | asked | confirmed | skipped
            if (! Schema::hasColumn('creative_brand_identities', 'intake_asked_at')) $t->timestamp('intake_asked_at')->nullable()->after('intake_status');
            if (! Schema::hasColumn('creative_brand_identities', 'confirmed_at')) $t->timestamp('confirmed_at')->nullable()->after('intake_asked_at');
            if (! Schema::hasColumn('creative_brand_identities', 'version')) $t->unsignedInteger('version')->default(1)->after('confirmed_at');
        });
        if (! Schema::hasTable('brand_profile_versions')) {
            Schema::create('brand_profile_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->unsignedBigInteger('brand_id')->index();
                $t->unsignedInteger('version');
                $t->json('snapshot_json');
                $t->string('reason', 191)->nullable();
                $t->string('source', 32)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profile_versions');
        Schema::table('creative_brand_identities', function (Blueprint $t) {
            foreach (['directions_json', 'rules_json', 'sources_json', 'assets_json', 'proposal_json', 'intake_status', 'intake_asked_at', 'confirmed_at', 'version'] as $c) {
                if (Schema::hasColumn('creative_brand_identities', $c)) $t->dropColumn($c);
            }
        });
    }
};

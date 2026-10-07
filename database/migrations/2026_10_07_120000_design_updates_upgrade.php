<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-OFFER-1 (Owner 2026-10-07, "okay go"): a design update can also move a website from its Classic design to one of
 * the new designs. kind = version (the same design, a newer version — every row before this) | upgrade (another design);
 * to_slug = the design an upgrade moves to; offer_group = the two or three suggestions shown together. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_updates', function (Blueprint $t) {
            if (! Schema::hasColumn('design_updates', 'kind')) $t->string('kind', 16)->default('version')->after('website_id');
            if (! Schema::hasColumn('design_updates', 'to_slug')) $t->string('to_slug', 80)->nullable()->after('design_slug');
            if (! Schema::hasColumn('design_updates', 'offer_group')) $t->string('offer_group', 40)->nullable()->index()->after('to_slug');
        });
    }

    public function down(): void
    {
        Schema::table('design_updates', function (Blueprint $t) {
            foreach (['offer_group', 'to_slug', 'kind'] as $c) if (Schema::hasColumn('design_updates', $c)) { if ($c === 'offer_group') $t->dropIndex(['offer_group']); $t->dropColumn($c); }
        });
    }
};

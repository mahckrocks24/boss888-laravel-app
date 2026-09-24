<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K4 (2026-09-25) — the identity facts a machine-readable Organization needs.
 *
 * `businesses` (RFC-0011, EV-1097) is the canonical business model and stays so.
 * It carried no phone, address, email, social identity, opening hours, served
 * area or schema.org type, so none of those could ever reach public structured
 * data however well they were known. Measured 2026-09-24: Adjang's address and
 * opening hours sat in websites.template_variables and appeared in the page
 * copy but in neither `address` nor `openingHours`; SG Travel's and MR Systems'
 * phone and email sat in websites.settings_json; AMG had none stored anywhere.
 *
 * Additive and nullable throughout. Nothing existing is renamed or dropped.
 *
 * Deliberately NOT added, so the schema stays the smallest coherent one:
 *   geo_json   — latitude/longitude. No customer supplies it and search engines
 *                geocode a postal address perfectly well.
 *   legal_name — for an SMB it repeats `name`. Add it when a customer needs it.
 *
 * identity_meta_json is the honesty column. A value copied out of a settings
 * blob or a page variable is a CANDIDATE, not a verified business fact, and the
 * composer may publish only what is owner-stated, verified or imported. The map
 * is {field: {source, from, at}} with source one of:
 *   owner_stated | verified | imported | observed | proposed | ai_generated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('phone', 64)->nullable()->after('location');
            $table->string('email', 191)->nullable()->after('phone');
            $table->json('address_json')->nullable()->after('email');
            $table->json('opening_hours_json')->nullable()->after('address_json');
            $table->json('sameas_json')->nullable()->after('opening_hours_json');
            $table->json('area_served_json')->nullable()->after('sameas_json');
            $table->string('founding_date', 32)->nullable()->after('area_served_json');
            $table->string('schema_type', 64)->nullable()->after('founding_date');
            $table->json('identity_meta_json')->nullable()->after('schema_type');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'email', 'address_json', 'opening_hours_json',
                'sameas_json', 'area_served_json', 'founding_date',
                'schema_type', 'identity_meta_json',
            ]);
        });
    }
};

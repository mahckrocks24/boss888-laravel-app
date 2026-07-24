<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Analytics (GA4) lives on the SAME Google connection as Search
 * Console — same OAuth tokens, same account. We just record which GA4
 * property this workspace reads. Additive columns on gsc_connections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsc_connections', function (Blueprint $table) {
            $table->string('ga_property_id')->nullable()->after('connected_email');
            $table->string('ga_property_name')->nullable()->after('ga_property_id');
        });
    }

    public function down(): void
    {
        Schema::table('gsc_connections', function (Blueprint $table) {
            $table->dropColumn(['ga_property_id', 'ga_property_name']);
        });
    }
};

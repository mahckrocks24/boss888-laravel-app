<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $existing = Schema::getColumnListing('contacts');

            if (!in_array('full_name', $existing))      $table->string('full_name')->nullable()->after('name');
            if (!in_array('first_name', $existing))     $table->string('first_name')->nullable()->after('full_name');
            if (!in_array('last_name', $existing))      $table->string('last_name')->nullable()->after('first_name');
            if (!in_array('company_name', $existing))   $table->string('company_name')->nullable()->after('company');
            if (!in_array('job_title', $existing))      $table->string('job_title')->nullable()->after('company_name');
            if (!in_array('stage', $existing))          $table->string('stage')->nullable()->after('source');
            if (!in_array('status', $existing))         $table->string('status')->nullable()->default('active')->after('stage');
            if (!in_array('category', $existing))       $table->string('category')->nullable()->after('status');
            if (!in_array('priority', $existing))       $table->string('priority')->nullable()->after('category');
            if (!in_array('estimated_value', $existing)) $table->decimal('estimated_value', 10, 2)->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $cols = ['full_name','first_name','last_name','company_name','job_title','stage','status','category','priority','estimated_value'];
            $existing = Schema::getColumnListing('contacts');
            $toDrop = array_intersect($cols, $existing);
            if (!empty($toDrop)) $table->dropColumn($toDrop);
        });
    }
};

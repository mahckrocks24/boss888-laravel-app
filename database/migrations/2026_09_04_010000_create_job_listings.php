<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KABAYAN888 JOBS-1 (2026-09-04) — the job portal's one table.
 *
 * A listing belongs to exactly one website inside one workspace (INC-0006 model). Only
 * status=published rows render; publishing requires an apply path (URL or email) and a
 * verification_source so the desk never publishes a vacancy it cannot point to. Employers
 * submit through the public "Post a job" form, which lands in `leads` (source=job_post);
 * the desk (or Sarah via the jobs engine) turns a verified submission into a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_listings')) return;
        Schema::create('job_listings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('website_id')->index();
            $table->string('slug', 190);
            $table->string('title', 190);
            $table->string('company', 160);
            $table->string('company_url', 512)->nullable();
            $table->string('company_logo_url', 2048)->nullable();
            $table->string('category_slug', 80)->nullable()->index();      // e.g. hospitality, healthcare, construction, office, retail, domestic, logistics, education, it, sales
            $table->string('employment_type', 24)->default('full_time');    // full_time | part_time | contract | temporary | internship
            $table->string('city', 96)->nullable()->index();
            $table->string('region', 96)->nullable();                        // emirate / state
            $table->char('country', 2)->default('AE')->index();
            $table->boolean('is_remote')->default(false);
            $table->unsignedInteger('salary_min')->nullable();
            $table->unsignedInteger('salary_max')->nullable();
            $table->char('salary_currency', 3)->default('AED');
            $table->string('salary_period', 12)->default('month');           // month | year | hour | day
            $table->string('salary_text', 120)->nullable();                  // "AED 4,000–5,500 + accommodation"
            $table->text('summary')->nullable();                             // 1–2 sentences for cards
            $table->longText('description')->nullable();                     // HTML (sanitised on write)
            $table->text('requirements')->nullable();                        // plain text, one per line
            $table->json('benefits_json')->nullable();                       // ["Visa", "Accommodation", "Flights"]
            $table->string('apply_url', 1024)->nullable();
            $table->string('apply_email', 190)->nullable();
            $table->string('apply_instructions', 500)->nullable();
            $table->string('source_url', 1024)->nullable();                  // where the vacancy was published
            $table->string('verification_source', 512)->nullable();          // REQUIRED to publish
            $table->string('status', 20)->default('draft')->index();         // draft | published | expired | archived
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('source', 24)->default('desk');                   // desk | sarah | employer
            $table->unsignedBigInteger('lead_id')->nullable();               // leads.id when created from a submission
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['website_id', 'slug'], 'uq_jobs_site_slug');
            $table->index(['website_id', 'status', 'country', 'city', 'category_slug'], 'ix_jobs_browse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RESUME888 Unit 1 (2026-09-07) — Kabayan Resume Builder tables. All PII lives in resume_sessions/resumes and is purged on schedule. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('resume_sessions')) {
            Schema::create('resume_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('website_id')->index();
                $t->string('token_hash', 64)->unique();      // visitor secret (sha256)
                $t->string('device_id', 64)->nullable()->index();
                $t->string('email', 190)->nullable()->index();
                $t->timestamp('email_verified_at')->nullable();
                $t->string('language', 8)->default('tl');   // tl (Taglish) | en | fil
                $t->string('path', 12)->nullable();          // build | enhance
                $t->string('region', 8)->nullable();         // AE | QA | ALL
                $t->string('state', 40)->default('start');   // current step id
                $t->json('draft_json')->nullable();          // resume data (schema v1)
                $t->json('progress_json')->nullable();       // answered steps, loop counters
                $t->unsignedSmallInteger('turns')->default(0);
                $t->unsignedSmallInteger('model_calls')->default(0);
                $t->unsignedSmallInteger('vision_calls')->default(0);
                $t->unsignedInteger('cost_usd_micro')->default(0);
                $t->timestamp('consent_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('expires_at')->nullable()->index();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (!Schema::hasTable('resumes')) {
            Schema::create('resumes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('session_id')->index();
                $t->unsignedBigInteger('website_id')->index();
                $t->unsignedSmallInteger('version')->default(1);
                $t->string('template', 20)->default('clean');
                $t->json('data_json');
                $t->string('content_hash', 64)->index();
                $t->string('pdf_path', 255)->nullable();
                $t->timestamp('pdf_generated_at')->nullable();
                $t->unsignedInteger('word_count')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('resume_uploads')) {
            Schema::create('resume_uploads', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('session_id')->index();
                $t->string('kind', 12);                      // pdf | image | docx
                $t->string('stored_path', 255)->nullable();
                $t->unsignedInteger('bytes')->default(0);
                $t->unsignedInteger('extracted_chars')->default(0);
                $t->string('method', 20)->nullable();        // pdftotext | phpword | tesseract | vision
                $t->unsignedTinyInteger('confidence')->default(0);
                $t->string('status', 16)->default('pending');
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('resume_events')) {
            Schema::create('resume_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('website_id')->index();
                $t->unsignedBigInteger('session_id')->nullable()->index();
                $t->string('event', 32);
                $t->unsignedInteger('cost_usd_micro')->default(0);
                $t->json('meta_json')->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }
        if (!Schema::hasTable('resume_cache')) {
            Schema::create('resume_cache', function (Blueprint $t) {
                $t->string('cache_key', 64)->primary();      // sha256(kind + input)
                $t->string('kind', 24);
                $t->json('value_json');
                $t->unsignedInteger('hits')->default(0);
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        foreach (['resume_cache', 'resume_events', 'resume_uploads', 'resumes', 'resume_sessions'] as $t) Schema::dropIfExists($t);
    }
};

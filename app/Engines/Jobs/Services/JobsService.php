<?php

namespace App\Engines\Jobs\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KABAYAN888 JOBS-1 (2026-09-04) — job portal engine.
 *
 * One service, four verbs: create, update, publish (expire/archive via status), list/get.
 * Every write is workspace + website scoped (INC-0006). Publishing is gated structurally:
 * a listing needs a title, company, location, an apply path (URL or email) and a
 * verification_source — the desk cannot publish a vacancy it cannot point to. HTML in
 * description is reduced to a safe subset. No provider calls, no credits.
 *
 * Reached through EngineExecutionService ('jobs' engine) and Sarah's jobs.* tools.
 */
class JobsService
{
    public const CATEGORIES = [
        'hospitality' => 'Hospitality and F&B', 'healthcare' => 'Healthcare', 'construction' => 'Construction and Engineering',
        'office' => 'Office and Admin', 'retail' => 'Retail and Sales', 'domestic' => 'Domestic and Household',
        'logistics' => 'Logistics and Drivers', 'education' => 'Education', 'it' => 'IT and Digital',
        'finance' => 'Finance and Accounting', 'beauty' => 'Beauty and Wellness', 'security' => 'Security and Facilities',
        'seafaring' => 'Seafaring and Offshore', 'other' => 'Other',
    ];
    public const TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'temporary' => 'Temporary', 'internship' => 'Internship'];
    public const STATUSES = ['draft', 'published', 'expired', 'archived'];

    public function create(int $wsId, array $data): array
    {
        $wid = $this->resolveWebsite($wsId, (int) ($data['website_id'] ?? 0));
        if ($wid <= 0) return ['success' => false, 'error' => 'WEBSITE_REQUIRED', 'message' => 'Which website should this job be listed on? Pass website_id.'];
        $title = trim((string) ($data['title'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        if ($title === '' || $company === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'title and company are required.'];
        $row = $this->normalise($data);
        $row['workspace_id'] = $wsId;
        $row['website_id'] = $wid;
        $row['slug'] = $this->uniqueSlug($wid, $title, $company);
        $row['status'] = 'draft';
        $row['source'] = in_array($data['source'] ?? '', ['desk', 'sarah', 'employer'], true) ? $data['source'] : 'sarah';
        $row['created_by'] = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $row['lead_id'] = isset($data['lead_id']) ? (int) $data['lead_id'] : null;
        $row['created_at'] = now(); $row['updated_at'] = now();
        $id = DB::table('job_listings')->insertGetId($row);
        $publish = !empty($data['publish']);
        $out = ['success' => true, 'job_id' => $id, 'slug' => $row['slug'], 'status' => 'draft', 'website_id' => $wid];
        if ($publish) { $p = $this->publish($wsId, $id); $out['status'] = $p['status'] ?? 'draft'; if (empty($p['success'])) $out['publish_error'] = $p['message'] ?? 'not published'; else $out['url'] = $p['url'] ?? null; }
        return $out;
    }

    public function update(int $wsId, int $jobId, array $data): array
    {
        $job = $this->find($wsId, $jobId);
        if (!$job) return ['success' => false, 'error' => 'NOT_FOUND', 'message' => "Job {$jobId} not found in this workspace."];
        $row = $this->normalise($data, true);
        if (array_key_exists('status', $data)) {
            $st = (string) $data['status'];
            if (!in_array($st, self::STATUSES, true)) return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Unknown status.'];
            if ($st === 'published') { $merged = array_merge((array) $job, $row); $why = $this->publishBlockers($merged); if ($why !== []) return ['success' => false, 'error' => 'NOT_PUBLISHABLE', 'message' => implode(' ', $why)]; if (empty($job->posted_at)) $row['posted_at'] = now(); }
            $row['status'] = $st;
        }
        if ($row === []) return ['success' => true, 'job_id' => $jobId, 'changed' => 0];
        $row['updated_at'] = now();
        DB::table('job_listings')->where('id', $jobId)->update($row);
        $this->invalidate((int) $job->website_id);
        return ['success' => true, 'job_id' => $jobId, 'changed' => count($row) - 1, 'status' => $row['status'] ?? $job->status];
    }

    public function publish(int $wsId, int $jobId): array
    {
        $job = $this->find($wsId, $jobId);
        if (!$job) return ['success' => false, 'error' => 'NOT_FOUND', 'message' => "Job {$jobId} not found."];
        $why = $this->publishBlockers((array) $job);
        if ($why !== []) return ['success' => false, 'error' => 'NOT_PUBLISHABLE', 'status' => $job->status, 'message' => implode(' ', $why)];
        DB::table('job_listings')->where('id', $jobId)->update([
            'status' => 'published', 'is_verified' => 1,
            'posted_at' => $job->posted_at ?: now(),
            'expires_at' => $job->expires_at ?: now()->addDays(45),
            'updated_at' => now(),
        ]);
        $this->invalidate((int) $job->website_id);
        return ['success' => true, 'job_id' => $jobId, 'status' => 'published', 'url' => $this->url($job)];
    }

    public function list(int $wsId, array $f = []): array
    {
        $q = DB::table('job_listings')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (!empty($f['website_id'])) $q->where('website_id', (int) $f['website_id']);
        if (!empty($f['status'])) $q->where('status', (string) $f['status']);
        if (!empty($f['category'])) $q->where('category_slug', (string) $f['category']);
        if (!empty($f['city'])) $q->where('city', (string) $f['city']);
        $limit = max(1, min(100, (int) ($f['limit'] ?? 25)));
        $rows = $q->orderByDesc('is_featured')->orderByDesc('posted_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'slug', 'title', 'company', 'category_slug', 'employment_type', 'city', 'country', 'salary_text', 'status', 'is_featured', 'posted_at', 'expires_at', 'website_id']);
        return ['success' => true, 'total' => $rows->count(), 'jobs' => $rows->map(fn ($r) => (array) $r + ['url' => $this->url($r)])->all()];
    }

    public function get(int $wsId, int $jobId): ?object { return $this->find($wsId, $jobId); }

    /** Published, unexpired listings for a website (renderer/API). */
    public function publicQuery(int $websiteId)
    {
        return DB::table('job_listings')->where('website_id', $websiteId)->where('status', 'published')->whereNull('deleted_at')
            ->where(function ($w) { $w->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
    }

    // ─── internals ─────────────────────────────────────────────────────────

    private function find(int $wsId, int $jobId): ?object
    {
        return DB::table('job_listings')->where('id', $jobId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
    }

    private function resolveWebsite(int $wsId, int $requested): int
    {
        $q = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('platform')->orWhere('platform', '!=', 'wordpress'));
        if ($requested > 0) return (clone $q)->where('id', $requested)->exists() ? $requested : 0;
        $ids = $q->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : 0; // ambiguous → caller must name the site (anti-INC-0005)
    }

    private function uniqueSlug(int $wid, string $title, string $company): string
    {
        $base = Str::slug(Str::limit($title . ' ' . $company, 80, '')) ?: 'job';
        $slug = $base; $i = 2;
        while (DB::table('job_listings')->where('website_id', $wid)->where('slug', $slug)->exists()) { $slug = $base . '-' . $i++; }
        return $slug;
    }

    private function normalise(array $d, bool $partial = false): array
    {
        $out = [];
        $set = function (string $key, $val) use (&$out, $partial, $d) { if ($partial && !array_key_exists($key, $d)) return; $out[$key] = $val; };
        $str = fn (string $k, int $max) => array_key_exists($k, $d) ? Str::limit(trim((string) $d[$k]), $max, '') : null;
        foreach (['title' => 190, 'company' => 160, 'city' => 96, 'region' => 96, 'salary_text' => 120, 'apply_instructions' => 500, 'company_url' => 512, 'apply_url' => 1024, 'source_url' => 1024, 'verification_source' => 512, 'apply_email' => 190, 'company_logo_url' => 2048] as $k => $max) {
            if (array_key_exists($k, $d)) $set($k, $str($k, $max) ?: null);
        }
        foreach (['company_url', 'apply_url', 'source_url', 'company_logo_url'] as $k) {
            if (isset($out[$k]) && $out[$k] !== null && !preg_match('#^https?://#i', $out[$k])) $out[$k] = null;
        }
        if (array_key_exists('apply_email', $d)) $out['apply_email'] = filter_var($out['apply_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $out['apply_email'] : null;
        if (array_key_exists('category', $d) || array_key_exists('category_slug', $d)) { $c = Str::slug((string) ($d['category_slug'] ?? $d['category'] ?? '')); $set('category_slug', isset(self::CATEGORIES[$c]) ? $c : 'other'); }
        if (array_key_exists('employment_type', $d)) { $t = str_replace('-', '_', strtolower((string) $d['employment_type'])); $set('employment_type', isset(self::TYPES[$t]) ? $t : 'full_time'); }
        if (array_key_exists('country', $d)) $set('country', strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $d['country']) ?: 'AE', 0, 2)));
        if (array_key_exists('is_remote', $d)) $set('is_remote', (int) (bool) $d['is_remote']);
        if (array_key_exists('is_featured', $d)) $set('is_featured', (int) (bool) $d['is_featured']);
        foreach (['salary_min', 'salary_max'] as $k) if (array_key_exists($k, $d)) $set($k, $d[$k] !== null && $d[$k] !== '' ? max(0, (int) $d[$k]) : null);
        if (array_key_exists('salary_currency', $d)) $set('salary_currency', strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $d['salary_currency']) ?: 'AED', 0, 3)));
        if (array_key_exists('salary_period', $d)) { $p = strtolower((string) $d['salary_period']); $set('salary_period', in_array($p, ['month', 'year', 'hour', 'day'], true) ? $p : 'month'); }
        if (array_key_exists('summary', $d)) $set('summary', Str::limit(trim(strip_tags((string) $d['summary'])), 400, ''));
        if (array_key_exists('description', $d)) $set('description', $this->safeHtml((string) $d['description']));
        if (array_key_exists('requirements', $d)) $set('requirements', is_array($d['requirements']) ? implode("\n", array_map('strval', $d['requirements'])) : trim(strip_tags((string) $d['requirements'])));
        if (array_key_exists('benefits', $d) || array_key_exists('benefits_json', $d)) { $b = $d['benefits_json'] ?? $d['benefits']; if (is_string($b)) $b = preg_split('/[,\n]+/', $b); $set('benefits_json', json_encode(array_values(array_filter(array_map(fn ($x) => trim(strip_tags((string) $x)), (array) $b))))); }
        if (array_key_exists('expires_at', $d)) { try { $set('expires_at', $d['expires_at'] ? \Carbon\Carbon::parse((string) $d['expires_at']) : null); } catch (\Throwable) {} }
        if (array_key_exists('posted_at', $d)) { try { $set('posted_at', $d['posted_at'] ? \Carbon\Carbon::parse((string) $d['posted_at']) : null); } catch (\Throwable) {} }
        return $out;
    }

    /** Reduce description HTML to a safe subset (no scripts, handlers, iframes, styles). */
    private function safeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (!preg_match('#<(p|ul|ol|li|h2|h3|br|strong|em|b|i|a)\b#i', $html)) {
            // plain text → paragraphs
            return implode('', array_map(fn ($p) => '<p>' . nl2br(e(trim($p))) . '</p>', array_filter(preg_split('/\n\s*\n/', $html), fn ($p) => trim($p) !== '')));
        }
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*/?>#is', '', $html) ?? $html;
        $html = strip_tags($html, '<p><ul><ol><li><h2><h3><h4><br><strong><em><b><i><a><blockquote>');
        $html = preg_replace('#\s(on[a-z]+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace_callback('#<a\b([^>]*)>#i', function ($m) {
            preg_match('#href\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $m[1], $h);
            $href = $h[2] ?? $h[3] ?? '';
            return preg_match('#^(https?://|mailto:)#i', $href) ? '<a href="' . e($href) . '" rel="nofollow noopener" target="_blank">' : '<a>';
        }, $html) ?? $html;
        return $html;
    }

    private function publishBlockers(array $j): array
    {
        $why = [];
        if (trim((string) ($j['title'] ?? '')) === '') $why[] = 'Missing title.';
        if (trim((string) ($j['company'] ?? '')) === '') $why[] = 'Missing company.';
        if (trim((string) ($j['city'] ?? '')) === '' && empty($j['is_remote'])) $why[] = 'Missing city (or mark it remote).';
        if (trim((string) ($j['description'] ?? '')) === '' && trim((string) ($j['summary'] ?? '')) === '') $why[] = 'Missing description.';
        if (empty($j['apply_url']) && empty($j['apply_email'])) $why[] = 'Missing apply_url or apply_email.';
        if (trim((string) ($j['verification_source'] ?? '')) === '') $why[] = 'Missing verification_source (the official posting URL or "phone confirmed <date>").';
        return $why;
    }

    private function url(object $job): ?string
    {
        $w = DB::table('websites')->where('id', (int) $job->website_id)->first(['subdomain', 'custom_domain', 'domain_verified']);
        if (!$w) return null;
        $origin = (!empty($w->custom_domain) && !empty($w->domain_verified)) ? 'https://' . $w->custom_domain : 'https://' . str_replace('.levelupgrowth.io', '', (string) $w->subdomain) . '.levelupgrowth.io';
        return $origin . '/jobs/' . $job->slug;
    }

    /** Forget the middleware page cache for the site's pages (jobs board + jobs page) without touching publish state. */
    private function invalidate(int $websiteId): void
    {
        try {
            $w = DB::table('websites')->where('id', $websiteId)->first(['subdomain']);
            if (!$w) return;
            $sub = str_replace('.levelupgrowth.io', '', (string) $w->subdomain);
            $slugs = DB::table('pages')->where('website_id', $websiteId)->pluck('slug')->all();
            foreach (array_unique(array_merge($slugs, ['home', 'jobs'])) as $slug) \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:{$slug}");
        } catch (\Throwable) {}
    }
}

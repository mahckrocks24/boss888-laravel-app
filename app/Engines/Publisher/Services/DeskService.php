<?php

namespace App\Engines\Publisher\Services;

use App\Core\EngineKernel\EngineExecutionService;
use App\Core\Workspaces\TeamService;
use App\Engines\Jobs\Services\JobsService;
use App\Engines\Write\Services\WriteService;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PUBLISHER888 Unit 1 (2026-09-04) — the Publisher Desk.
 *
 * A backend for websites built on a publishing theme (today: `kabayan-news`), served at
 * `{site-host}/admin` by PublishedSiteMiddleware and driven by `/api/desk/*` (JWT auth,
 * workspace-scoped, website resolved from the host or `X-Desk-Website`).
 *
 * The desk owns NO content logic of its own: stories are `articles` (WriteService), jobs are
 * `job_listings` (JobsService), the inbox is `leads`/`activities`, commissioning is a
 * `write/write_article` task through the engine kernel. The desk adds role resolution,
 * website binding, publish gates, and cache invalidation.
 */
class DeskService
{
    /** Themes that ship a desk. Extend when another publishing theme lands. */
    public const THEMES = ['kabayan-news'];

    public const ROLES = ['owner', 'editor', 'moderator', 'viewer'];

    /** ability => roles allowed */
    public const ABILITIES = [
        'stories.read'    => ['owner', 'editor', 'moderator', 'viewer'],
        'stories.write'   => ['owner', 'editor'],
        'stories.publish' => ['owner', 'editor'],
        'sections.write'  => ['owner', 'editor'],
        'commission'      => ['owner', 'editor'],
        'jobs.read'       => ['owner', 'editor', 'moderator', 'viewer'],
        'jobs.write'      => ['owner', 'moderator', 'editor'],
        'jobs.publish'    => ['owner', 'moderator'],
        'inbox.read'      => ['owner', 'editor', 'moderator', 'viewer'],
        'inbox.write'     => ['owner', 'editor', 'moderator'],
        'members.read'    => ['owner', 'editor', 'moderator', 'viewer'],
        'members.write'   => ['owner'],
        'audit.read'      => ['owner', 'editor'],
    ];

    public const STORY_TYPES = ['news', 'article', 'feature', 'opinion', 'guide'];

    public function __construct(
        private WriteService $write,
        private JobsService $jobs,
        private TeamService $team,
        private EngineExecutionService $kernel,
        private DeskAudit $audit,
    ) {}

    // ─── theme / host / role ────────────────────────────────────────────────

    public static function themeHasDesk(object|array $website): bool
    {
        $w = (array) $website;
        $s = is_string($w['settings_json'] ?? null) ? (json_decode($w['settings_json'], true) ?: []) : (array) ($w['settings_json'] ?? []);
        return in_array((string) ($s['theme'] ?? ''), self::THEMES, true);
    }

    public static function can(?string $role, string $ability): bool
    {
        return $role !== null && in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    /** Effective desk role: explicit desk_members row, else mapped from the workspace role. */
    public function resolveRole(int $wsId, int $websiteId, int $userId, ?string $wsRole): ?string
    {
        $explicit = $userId > 0 ? DB::table('desk_members')->where('website_id', $websiteId)->where('user_id', $userId)->value('role') : null;
        if ($explicit && in_array($explicit, self::ROLES, true)) return $explicit;
        $wsRole = $wsRole ?: $this->team->getUserRole($wsId, $userId);
        return match ($wsRole) {
            'owner', 'admin' => 'owner',
            'member'         => 'editor',
            'viewer'         => 'viewer',
            default          => null,
        };
    }

    public function settings(object $website): array
    {
        return is_string($website->settings_json ?? null) ? (json_decode($website->settings_json, true) ?: []) : [];
    }

    public function siteOrigin(object $website): string
    {
        $cd = strtolower(trim((string) ($website->custom_domain ?? ''), " /"));
        if ($cd !== '' && !empty($website->domain_verified) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $cd)) return 'https://' . $cd;
        $sub = str_replace('.levelupgrowth.io', '', (string) ($website->subdomain ?? ''));
        return "https://{$sub}.levelupgrowth.io";
    }

    /** KABAYAN888 QATAR-1 — editions from settings_json.regions ([{code,name,short,tz,tz_label}] or legacy ["ae"]). */
    public const REGION_DEFAULTS = [
        'AE' => ['name' => 'United Arab Emirates', 'short' => 'UAE', 'tz' => 'Asia/Dubai', 'tz_label' => 'GST', 'currency' => 'AED'],
        'QA' => ['name' => 'Qatar', 'short' => 'Qatar', 'tz' => 'Asia/Qatar', 'tz_label' => 'AST', 'currency' => 'QAR'],
        'SA' => ['name' => 'Saudi Arabia', 'short' => 'KSA', 'tz' => 'Asia/Riyadh', 'tz_label' => 'AST', 'currency' => 'SAR'],
        'KW' => ['name' => 'Kuwait', 'short' => 'Kuwait', 'tz' => 'Asia/Kuwait', 'tz_label' => 'AST', 'currency' => 'KWD'],
        'BH' => ['name' => 'Bahrain', 'short' => 'Bahrain', 'tz' => 'Asia/Bahrain', 'tz_label' => 'AST', 'currency' => 'BHD'],
        'OM' => ['name' => 'Oman', 'short' => 'Oman', 'tz' => 'Asia/Muscat', 'tz_label' => 'GST', 'currency' => 'OMR'],
    ];
    public function regions(object $website): array
    {
        $out = [];
        foreach ((array) ($this->settings($website)['regions'] ?? []) as $r) {
            if (is_string($r)) $r = ['code' => $r];
            if (!is_array($r) || empty($r['code'])) continue;
            $code = strtoupper((string) $r['code']);
            $out[] = array_merge(self::REGION_DEFAULTS[$code] ?? ['name' => $code, 'short' => $code, 'tz' => 'Asia/Dubai', 'tz_label' => 'GST', 'currency' => ''], array_filter($r, fn ($v) => $v !== null && $v !== ''), ['code' => $code]);
        }
        return $out;
    }
    private function regionCode(object $website, $v): ?string
    {
        $v = strtoupper(trim((string) $v));
        if ($v === '' || $v === 'ALL') return null;
        foreach ($this->regions($website) as $r) if ($r['code'] === $v) return $v;
        return null;
    }
    /** "Qatar" / "UAE" / "United Arab Emirates" / "QA" → code (for parsing form submissions). */
    private function regionFromText(object $website, string $text): ?string
    {
        $t = strtolower(trim($text)); if ($t === '') return null;
        foreach ($this->regions($website) as $r) {
            if (in_array($t, array_map('strtolower', [$r['code'], $r['name'], $r['short']]), true)) return $r['code'];
            if (str_contains($t, strtolower($r['name'])) || str_contains($t, strtolower($r['short']))) return $r['code'];
        }
        return null;
    }

    public function articleBase(object $website): string
    {
        $b = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($this->settings($website)['article_base'] ?? 'news')));
        return $b !== '' ? $b : 'news';
    }

    /** The desk shell (HTML). Served by PublishedSiteMiddleware for GET /admin[/…] on a desk-enabled site. */
    public function shell(object $website, Request $request)
    {
        $s = $this->settings($website);
        $brand = is_string($website->template_variables ?? null) ? (json_decode($website->template_variables, true) ?: []) : [];
        $desk = [
            'website_id'   => (int) $website->id,
            'workspace_id' => (int) $website->workspace_id,
            'site_name'    => (string) ($website->name ?? 'Site'),
            'origin'       => $this->siteOrigin($website),
            'article_base' => $this->articleBase($website),
            'theme'        => (string) ($s['theme'] ?? ''),
            'primary'      => (string) ($brand['brand']['primary'] ?? $s['colours']['primary'] ?? $s['primary'] ?? '#0038A8'),
            'accent'       => (string) ($brand['brand']['accent'] ?? $s['colours']['accent'] ?? $s['accent'] ?? '#FCD116'),
            'api'          => '/api/',
        ];
        $v = @filemtime(public_path('desk/desk.js')) ?: time();
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        // Unit 2 — strict CSP: only our own scripts (nonce-bound) and Google Fonts; no framing, no plugins, no form posts elsewhere.
        $csp = implode('; ', [
            "default-src 'self'", "script-src 'self' 'nonce-{$nonce}'", "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:", "connect-src 'self'", "frame-ancestors 'none'", "frame-src 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "upgrade-insecure-requests",
        ]);
        return response()->view('desk.shell', ['desk' => $desk, 'v' => $v, 'nonce' => $nonce])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Served-By', 'publisher-desk')
            ->header('Content-Security-Policy', $csp)
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->header('Cross-Origin-Opener-Policy', 'same-origin');
    }

    // ─── context / dashboard ────────────────────────────────────────────────

    public function context(object $website, object $user, string $role): array
    {
        $wsId = (int) $website->workspace_id; $wid = (int) $website->id;
        $abilities = [];
        foreach (self::ABILITIES as $k => $roles) if (in_array($role, $roles, true)) $abilities[] = $k;
        return [
            'success'  => true,
            'site'     => [
                'id' => $wid, 'workspace_id' => $wsId, 'name' => $website->name, 'origin' => $this->siteOrigin($website),
                'article_base' => $this->articleBase($website), 'theme' => $this->settings($website)['theme'] ?? null,
                'subdomain' => $website->subdomain, 'custom_domain' => $website->custom_domain,
            ],
            'user'     => ['id' => (int) $user->id, 'name' => $user->name ?? '', 'email' => $user->email ?? ''],
            'role'     => $role,
            'abilities'=> $abilities,
            'sections' => $this->listSections($wsId, $wid),
            'regions'  => $this->regions($website), // QATAR-1 editions
            'counts'   => $this->counts($wsId, $wid),
            'enums'    => ['story_types' => self::STORY_TYPES, 'job_categories' => self::enumList(JobsService::CATEGORIES), 'job_types' => self::enumList(JobsService::TYPES),
                           'lead_statuses' => ['new', 'contacted', 'qualified', 'converted', 'lost'], 'roles' => self::ROLES],
        ];
    }

    /** Normalise a constant list (either ['a','b'] or ['a' => 'Label']) to [{value,label}] for the UI. */
    public static function enumList(array $c): array
    {
        $out = [];
        foreach ($c as $k => $v) {
            $isAssoc = is_string($k);
            $value = $isAssoc ? $k : (is_array($v) ? ($v['slug'] ?? $v['value'] ?? '') : $v);
            $label = $isAssoc ? (is_array($v) ? ($v['label'] ?? $v['name'] ?? $k) : $v) : (is_array($v) ? ($v['label'] ?? $v['name'] ?? $value) : ucwords(str_replace(['_', '-'], ' ', (string) $v)));
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        return $out;
    }

    public function counts(int $wsId, int $wid): array
    {
        $st = DB::table('articles')->where('website_id', $wid)->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status')->all();
        $jobs = DB::table('job_listings')->where('website_id', $wid)->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status')->all();
        $expiring = DB::table('job_listings')->where('website_id', $wid)->whereNull('deleted_at')->where('status', 'published')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(7))->count();
        $inbox = DB::table('leads')->where('website_id', $wid)->whereNull('deleted_at')->where('status', 'new')
            ->select('source', DB::raw('count(*) c'))->groupBy('source')->pluck('c', 'source')->all();
        $commissions = DB::table('desk_commissions')->where('website_id', $wid)->whereIn('status', ['queued', 'awaiting_approval'])->count();
        $lastPublished = DB::table('articles')->where('website_id', $wid)->whereNull('deleted_at')->where('status', 'published')->max('published_at');
        return [
            'stories' => ['published' => (int) ($st['published'] ?? 0), 'draft' => (int) ($st['draft'] ?? 0), 'scheduled' => (int) ($st['scheduled'] ?? 0)],
            'commissions_open' => $commissions,
            'jobs' => ['published' => (int) ($jobs['published'] ?? 0), 'draft' => (int) ($jobs['draft'] ?? 0), 'expired' => (int) ($jobs['expired'] ?? 0), 'expiring_7d' => $expiring],
            'inbox_new' => ['total' => array_sum($inbox), 'by_source' => $inbox],
            'last_published_at' => $lastPublished,
        ];
    }

    // ─── sections (blog_categories) ─────────────────────────────────────────

    public function listSections(int $wsId, int $wid): array
    {
        $counts = DB::table('articles')->where('website_id', $wid)->whereNull('deleted_at')
            ->select('blog_category', DB::raw('count(*) c'), DB::raw("sum(status='published') p"))->groupBy('blog_category')->get()->keyBy('blog_category');
        $pages = DB::table('pages')->where('website_id', $wid)->pluck('slug')->flip();
        return DB::table('blog_categories')->where('workspace_id', $wsId)->orderBy('id')->get()->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'slug' => $c->slug,
            'stories' => (int) ($counts[$c->slug]->c ?? 0), 'published' => (int) ($counts[$c->slug]->p ?? 0),
            'has_page' => isset($pages[$c->slug]),
        ])->all();
    }

    public function createSection(int $wsId, array $d): array
    {
        $name = trim((string) ($d['name'] ?? '')); if ($name === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Name is required.'];
        $slug = Str::slug((string) ($d['slug'] ?? $name)); if ($slug === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Slug is required.'];
        if (DB::table('blog_categories')->where('workspace_id', $wsId)->where('slug', $slug)->exists()) return ['success' => false, 'error' => 'DUPLICATE', 'message' => "A section with slug '{$slug}' already exists."];
        $id = DB::table('blog_categories')->insertGetId(['workspace_id' => $wsId, 'name' => mb_substr($name, 0, 100), 'slug' => mb_substr($slug, 0, 100), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('section.create', 'section', $id, $name, null, ['slug' => $slug]);
        return ['success' => true, 'id' => $id, 'slug' => $slug];
    }

    public function updateSection(int $wsId, int $wid, int $id, array $d): array
    {
        $c = DB::table('blog_categories')->where('workspace_id', $wsId)->where('id', $id)->first();
        if (!$c) return ['success' => false, 'error' => 'NOT_FOUND'];
        $up = [];
        if (isset($d['name']) && trim($d['name']) !== '') $up['name'] = mb_substr(trim($d['name']), 0, 100);
        if (isset($d['slug'])) {
            $slug = Str::slug((string) $d['slug']);
            if ($slug !== '' && $slug !== $c->slug) {
                if (DB::table('blog_categories')->where('workspace_id', $wsId)->where('slug', $slug)->exists()) return ['success' => false, 'error' => 'DUPLICATE'];
                $up['slug'] = $slug;
                DB::table('articles')->where('workspace_id', $wsId)->where('blog_category', $c->slug)->update(['blog_category' => $slug]);
            }
        }
        if ($up) { $up['updated_at'] = now(); DB::table('blog_categories')->where('id', $id)->update($up); $this->audit->record('section.update', 'section', $id, $c->name, ['name' => $c->name, 'slug' => $c->slug], $up); }
        return ['success' => true];
    }

    public function deleteSection(int $wsId, int $wid, int $id): array
    {
        $c = DB::table('blog_categories')->where('workspace_id', $wsId)->where('id', $id)->first();
        if (!$c) return ['success' => false, 'error' => 'NOT_FOUND'];
        $n = DB::table('articles')->where('workspace_id', $wsId)->where('blog_category', $c->slug)->whereNull('deleted_at')->count();
        if ($n > 0) return ['success' => false, 'error' => 'IN_USE', 'message' => "{$n} stories still use this section. Move them first."];
        DB::table('blog_categories')->where('id', $id)->delete();
        $this->audit->record('section.delete', 'section', $id, $c->name, ['slug' => $c->slug], null);
        return ['success' => true];
    }

    // ─── stories (articles) ─────────────────────────────────────────────────

    public function listStories(int $wsId, int $wid, array $f = []): array
    {
        $q = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid);
        if (($f['status'] ?? '') === 'trash') $q->whereNotNull('deleted_at'); // Unit 2: recoverable bin (soft-deleted)
        else { $q->whereNull('deleted_at'); if (!empty($f['status']) && $f['status'] !== 'all') $q->where('status', $f['status']); }
        if (!empty($f['section'])) $q->where('blog_category', $f['section']);
        $regionF = strtoupper(trim((string) ($f['region'] ?? ''))); // QATAR-1: exact edition (ALL = stories marked for every edition)
        if ($regionF === 'ALL') $q->where(fn ($w) => $w->whereRaw("JSON_EXTRACT(brief_json, '$.region') IS NULL")->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(brief_json, '$.region'))) IN ('', 'ALL')"));
        elseif ($regionF !== '') $q->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(brief_json, '$.region'))) = ?", [$regionF]);
        if (!empty($f['q'])) { $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['q'])) . '%'; $q->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('slug', 'like', $s)); }
        $total = (clone $q)->count();
        $limit = max(1, min(100, (int) ($f['limit'] ?? 30))); $offset = max(0, (int) ($f['offset'] ?? 0));
        $rows = $q->orderByRaw("FIELD(status,'scheduled','draft','published')")->orderByDesc('updated_at')->offset($offset)->limit($limit)
            ->get(['id', 'title', 'slug', 'status', 'type', 'blog_category', 'excerpt', 'featured_image_url', 'word_count', 'read_time', 'brief_json', 'published_at', 'scheduled_at', 'updated_at', 'created_at', 'deleted_at']);
        $website = DB::table('websites')->where('id', $wid)->first();
        $names = DB::table('blog_categories')->where('workspace_id', $wsId)->pluck('name', 'slug')->all();
        return ['success' => true, 'stories' => $rows->map(fn ($a) => $this->storyRow($a, $website, $names) + ['deleted_at' => $a->deleted_at])->all(), 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + $rows->count() < $total];
    }

    public function restoreStory(int $wsId, int $wid, int $userId, int $id): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNotNull('deleted_at')->first(['id', 'title', 'deleted_at']);
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        DB::table('articles')->where('id', $id)->update(['deleted_at' => null, 'status' => 'draft', 'updated_at' => now()]);
        $this->audit->record('story.restore', 'story', $id, $a->title, ['deleted_at' => $a->deleted_at], ['status' => 'draft']);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    public function storyVersions(int $wsId, int $wid, int $id): array
    {
        if (!DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->exists()) return ['success' => false, 'error' => 'NOT_FOUND'];
        $names = DB::table('users')->whereIn('id', DB::table('article_versions')->where('article_id', $id)->whereNotNull('changed_by')->pluck('changed_by'))->pluck('name', 'id')->all();
        $rows = array_map(fn ($v) => ['id' => (int) $v->id, 'version' => (int) $v->version_number, 'summary' => $v->change_summary, 'by' => $names[$v->changed_by] ?? null, 'words' => str_word_count(strip_tags((string) $v->content)), 'created_at' => $v->created_at], $this->write->getVersions($id, $wsId));
        return ['success' => true, 'versions' => $rows];
    }

    public function restoreStoryVersion(int $wsId, int $wid, int $userId, int $id, int $vid): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first(['id', 'title', 'status']);
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        try { $this->write->restoreVersion($id, $vid, $wsId); } catch (\Throwable $e) { return ['success' => false, 'error' => 'NOT_FOUND', 'message' => 'That version no longer exists.']; }
        $this->audit->record('story.restore_version', 'story', $id, $a->title, null, ['version_id' => $vid]);
        if ($a->status === 'published') $this->invalidate($wid, $id);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    private function storyRow(object $a, object $website, array $names, bool $full = false): array
    {
        $brief = is_string($a->brief_json ?? null) ? (json_decode($a->brief_json, true) ?: []) : [];
        $row = [
            'id' => (int) $a->id, 'title' => $a->title, 'slug' => $a->slug, 'status' => $a->status, 'type' => $a->type,
            'section' => $a->blog_category, 'section_name' => $names[$a->blog_category] ?? $a->blog_category,
            'excerpt' => $a->excerpt, 'featured_image_url' => $a->featured_image_url, 'word_count' => (int) $a->word_count, 'read_time' => $a->read_time,
            'author' => $brief['author'] ?? null, 'region' => strtoupper((string) ($brief['region'] ?? '')) ?: 'ALL', 'published_at' => $a->published_at, 'scheduled_at' => $a->scheduled_at, 'updated_at' => $a->updated_at, 'created_at' => $a->created_at,
            'url' => $a->status === 'published' ? $this->siteOrigin($website) . '/' . $this->articleBase($website) . '/' . $a->slug : null,
        ];
        if ($full) {
            $row += [
                'content' => $a->content, 'featured_image_alt' => $a->featured_image_alt ?? null, 'meta_title' => $a->meta_title ?? null, 'meta_description' => $a->meta_description ?? null,
                'tags' => is_string($a->tags_json ?? null) ? (json_decode($a->tags_json, true) ?: []) : [],
                'sources' => $brief['sources'] ?? [], 'image_caption' => $brief['image_caption'] ?? null, 'image_credit' => $brief['image_credit'] ?? ($brief['credit'] ?? null),
                'brief' => $brief,
            ];
        }
        return $row;
    }

    public function getStory(int $wsId, int $wid, int $id): ?array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$a) return null;
        $website = DB::table('websites')->where('id', $wid)->first();
        $names = DB::table('blog_categories')->where('workspace_id', $wsId)->pluck('name', 'slug')->all();
        return $this->storyRow($a, $website, $names, true);
    }

    public function createStory(int $wsId, int $wid, int $userId, array $d): array
    {
        $title = trim((string) ($d['title'] ?? '')); if ($title === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Title is required.'];
        $section = $this->sectionSlug($wsId, $d['section'] ?? null);
        $res = $this->write->createArticle($wsId, [
            'title' => $title, 'content' => $this->cleanHtml((string) ($d['content'] ?? '')), 'excerpt' => mb_substr(trim((string) ($d['excerpt'] ?? '')), 0, 500),
            'type' => in_array($d['type'] ?? '', self::STORY_TYPES, true) ? $d['type'] : 'article', 'blog_category' => $section, 'is_marketing_blog' => 1, 'user_id' => $userId,
        ]);
        $id = (int) ($res['article_id'] ?? $res['id'] ?? 0);
        if ($id <= 0) return ['success' => false, 'error' => 'CREATE_FAILED', 'message' => $res['error'] ?? 'Could not create the story.'];
        DB::table('articles')->where('id', $id)->update(['website_id' => $wid, 'is_marketing_blog' => 1, 'updated_at' => now()]);
        $site = DB::table('websites')->where('id', $wid)->first();
        $this->mergeBrief($id, ['author' => $d['author'] ?? $this->deskAuthor($wid), 'sources' => $this->cleanSources($d['sources'] ?? []), 'image_caption' => $d['image_caption'] ?? null, 'image_credit' => $d['image_credit'] ?? null, 'region' => $this->regionCode($site, $d['region'] ?? null), 'desk' => ['created_by' => $userId]]);
        if (!empty($d['featured_image_url'])) DB::table('articles')->where('id', $id)->update(['featured_image_url' => $this->cleanUrl($d['featured_image_url']), 'featured_image_alt' => mb_substr((string) ($d['featured_image_alt'] ?? ''), 0, 255)]);
        $this->audit->record('story.create', 'story', $id, $title, null, ['section' => $section, 'type' => $d['type'] ?? 'article']);
        return ['success' => true, 'id' => $id, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    public function updateStory(int $wsId, int $wid, int $userId, int $id, array $d): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first(['id', 'status', 'title', 'blog_category', 'updated_at']);
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        if ($this->stale($a, $d)) return ['success' => false, 'error' => 'STALE', 'message' => 'Someone saved this story after you opened it. Reload to see their changes, or save again to overwrite.', 'story' => $this->getStory($wsId, $wid, $id)];
        $data = [];
        if (array_key_exists('title', $d)) { $t = trim((string) $d['title']); if ($t === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Title is required.']; $data['title'] = mb_substr($t, 0, 255); }
        if (array_key_exists('content', $d)) $data['content'] = $this->cleanHtml((string) $d['content']);
        if (array_key_exists('excerpt', $d)) $data['excerpt'] = mb_substr(trim((string) $d['excerpt']), 0, 500);
        if (array_key_exists('type', $d) && in_array($d['type'], self::STORY_TYPES, true)) $data['type'] = $d['type'];
        if (array_key_exists('section', $d)) $data['blog_category'] = $this->sectionSlug($wsId, $d['section']);
        if (array_key_exists('meta_title', $d)) $data['meta_title'] = mb_substr(trim((string) $d['meta_title']), 0, 255);
        if (array_key_exists('meta_description', $d)) $data['meta_description'] = mb_substr(trim((string) $d['meta_description']), 0, 320);
        if (array_key_exists('featured_image_url', $d)) $data['featured_image_url'] = $this->cleanUrl((string) $d['featured_image_url']);
        if ($data) $this->write->updateArticle($id, $data, $wsId); // keeps article_versions
        $direct = [];
        if (array_key_exists('featured_image_alt', $d)) $direct['featured_image_alt'] = mb_substr((string) $d['featured_image_alt'], 0, 255);
        if (array_key_exists('tags', $d)) $direct['tags_json'] = json_encode(array_values(array_filter(array_map(fn ($t) => mb_substr(Str::slug((string) $t), 0, 40), (array) $d['tags']))));
        if (array_key_exists('slug', $d) && $a->status !== 'published') { $s = Str::slug((string) $d['slug']); if ($s !== '' && !DB::table('articles')->where('workspace_id', $wsId)->where('slug', $s)->where('id', '!=', $id)->exists()) $direct['slug'] = $s; }
        if ($direct) { $direct['updated_at'] = now(); DB::table('articles')->where('id', $id)->update($direct); }
        $brief = [];
        foreach (['author', 'image_caption', 'image_credit'] as $k) if (array_key_exists($k, $d)) $brief[$k] = $d[$k] === null ? null : mb_substr(trim((string) $d[$k]), 0, 200);
        if (array_key_exists('sources', $d)) $brief['sources'] = $this->cleanSources($d['sources']);
        if (array_key_exists('region', $d)) $brief['region'] = $this->regionCode(DB::table('websites')->where('id', $wid)->first(), $d['region']); // null = every edition
        if ($brief) $this->mergeBrief($id, $brief + ['desk' => ['updated_by' => $userId]]);
        if ($a->status === 'published') $this->invalidate($wid, $id);
        $this->audit->record('story.update', 'story', $id, $data['title'] ?? $a->title, ['title' => $a->title, 'section' => $a->blog_category], ['fields' => array_keys($data + $direct + $brief)]);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    /** Publish gate + flip. Human action from the desk: no kernel approval, but the same blockers a wire editor would apply. */
    public function publishStory(int $wsId, int $wid, int $userId, int $id): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        $blockers = $this->publishBlockers($wsId, $a);
        if ($blockers) return ['success' => false, 'error' => 'NOT_PUBLISHABLE', 'blockers' => $blockers, 'message' => implode(' ', $blockers)];
        $up = ['status' => 'published', 'is_marketing_blog' => 1, 'scheduled_at' => null, 'updated_at' => now()];
        if (empty($a->published_at)) $up['published_at'] = now();
        if (trim((string) $a->excerpt) === '') $up['excerpt'] = $this->autoExcerpt((string) $a->content);
        DB::table('articles')->where('id', $id)->update($up);
        $this->mergeBrief($id, ['desk' => ['published_by' => $userId, 'published_via' => 'desk']]);
        $this->invalidate($wid, $id);
        $this->audit->record('story.publish', 'story', $id, $a->title, ['status' => $a->status], ['status' => 'published']);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    public function scheduleStory(int $wsId, int $wid, int $userId, int $id, ?string $at): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        $ts = $at ? strtotime($at) : false;
        if ($ts === false || $ts < time() + 60) return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Pick a time at least a minute in the future.'];
        $blockers = $this->publishBlockers($wsId, $a);
        if ($blockers) return ['success' => false, 'error' => 'NOT_PUBLISHABLE', 'blockers' => $blockers, 'message' => implode(' ', $blockers)];
        DB::table('articles')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s', $ts), 'is_marketing_blog' => 1, 'updated_at' => now()]);
        $this->mergeBrief($id, ['desk' => ['scheduled_by' => $userId]]);
        $this->invalidate($wid, $id);
        $this->audit->record('story.schedule', 'story', $id, $a->title, ['status' => $a->status], ['status' => 'scheduled', 'at' => date('c', $ts)]);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    public function unpublishStory(int $wsId, int $wid, int $userId, int $id): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first(['id', 'title', 'status']);
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        DB::table('articles')->where('id', $id)->update(['status' => 'draft', 'scheduled_at' => null, 'updated_at' => now()]);
        $this->mergeBrief($id, ['desk' => ['unpublished_by' => $userId]]);
        $this->invalidate($wid, $id);
        $this->audit->record('story.unpublish', 'story', $id, $a->title, ['status' => $a->status], ['status' => 'draft']);
        return ['success' => true, 'story' => $this->getStory($wsId, $wid, $id)];
    }

    public function deleteStory(int $wsId, int $wid, int $userId, int $id): array
    {
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first(['id', 'title', 'status']);
        if (!$a) return ['success' => false, 'error' => 'NOT_FOUND'];
        DB::table('articles')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'draft', 'updated_at' => now()]); // soft delete (recoverable from the bin); WriteService::deleteArticle is a hard delete
        $this->invalidate($wid, $id);
        $this->audit->record('story.delete', 'story', $id, $a->title, ['status' => $a->status], ['deleted' => true]);
        return ['success' => true];
    }

    /** Scheduled → published sweep (publisher:publish-scheduled). Only recent, site-bound articles; stale rows are reported, never auto-published. */
    public function publishDue(int $graceHours = 24): array
    {
        $due = DB::table('articles')->where('status', 'scheduled')->whereNotNull('website_id')->whereNull('deleted_at')
            ->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->where('scheduled_at', '>=', now()->subHours($graceHours))->get(['id', 'website_id', 'published_at', 'excerpt', 'content']);
        $published = [];
        foreach ($due as $a) {
            $up = ['status' => 'published', 'is_marketing_blog' => 1, 'updated_at' => now()];
            if (empty($a->published_at)) $up['published_at'] = now();
            if (trim((string) $a->excerpt) === '') $up['excerpt'] = $this->autoExcerpt((string) $a->content);
            DB::table('articles')->where('id', $a->id)->update($up);
            $this->invalidate((int) $a->website_id, (int) $a->id);
            $published[] = (int) $a->id;
        }
        $stale = DB::table('articles')->where('status', 'scheduled')->whereNull('deleted_at')->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->subHours($graceHours))->count();
        return ['published' => $published, 'stale_skipped' => $stale];
    }

    private function publishBlockers(int $wsId, object $a): array
    {
        $b = [];
        if (trim((string) $a->title) === '') $b[] = 'Title is missing.';
        if (trim((string) $a->blog_category) === '' || !DB::table('blog_categories')->where('workspace_id', $wsId)->where('slug', $a->blog_category)->exists()) $b[] = 'Pick a section.';
        $words = str_word_count(strip_tags((string) $a->content));
        if ($words < 80) $b[] = "Story is too short ({$words} words; at least 80).";
        return $b;
    }

    private function sectionSlug(int $wsId, $v): ?string
    {
        $slug = Str::slug((string) $v); if ($slug === '') return null;
        return DB::table('blog_categories')->where('workspace_id', $wsId)->where('slug', $slug)->exists() ? $slug : null;
    }

    private function deskAuthor(int $wid): string
    {
        $name = (string) (DB::table('websites')->where('id', $wid)->value('name') ?: 'Editorial');
        return $name . ' Desk';
    }

    private function mergeBrief(int $id, array $patch): void
    {
        $raw = DB::table('articles')->where('id', $id)->value('brief_json');
        $brief = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        foreach ($patch as $k => $v) {
            if ($k === 'desk') { $brief['desk'] = array_merge((array) ($brief['desk'] ?? []), $v); continue; }
            if ($v === null) unset($brief[$k]); else $brief[$k] = $v;
        }
        DB::table('articles')->where('id', $id)->update(['brief_json' => json_encode($brief), 'updated_at' => now()]);
    }

    private function autoExcerpt(string $html): string
    {
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
        return mb_strlen($t) > 180 ? rtrim(mb_substr($t, 0, 177)) . '…' : $t;
    }

    private function cleanSources($sources): array
    {
        $out = [];
        foreach ((array) $sources as $s) {
            if (is_string($s)) $s = ['url' => $s];
            $url = $this->cleanUrl((string) ($s['url'] ?? '')); $label = mb_substr(trim((string) ($s['label'] ?? $s['title'] ?? '')), 0, 160);
            if ($url === '' && $label === '') continue;
            $out[] = array_filter(['label' => $label ?: null, 'url' => $url ?: null]);
        }
        return array_slice($out, 0, 20);
    }

    public function cleanUrl(string $u): string
    {
        $u = trim($u); if ($u === '') return '';
        if (str_starts_with($u, '/')) return mb_substr($u, 0, 2048);
        return preg_match('#^https?://#i', $u) ? mb_substr($u, 0, 2048) : '';
    }

    /** Allow-list HTML for story/job bodies (editor output). Unit 2: DOM-based sanitiser (DeskHtmlSanitizer). */
    public function cleanHtml(string $html): string
    {
        return DeskHtmlSanitizer::clean($html);
    }

    /** Optimistic lock: the client sends the updated_at it loaded; a different value means someone else saved first. */
    private function stale(object $row, array $d): bool
    {
        $exp = trim((string) ($d['expected_updated_at'] ?? ''));
        if ($exp === '') return false;
        $cur = (string) ($row->updated_at ?? '');
        if ($cur === '') return false;
        $a = strtotime($exp); $b = strtotime($cur);
        return $a === false || $b === false ? $exp !== $cur : $a !== $b;
    }

    /** Bust the published-site cache for every page slug and the story's own path. */
    public function invalidate(int $wid, ?int $articleId = null): void
    {
        $website = DB::table('websites')->where('id', $wid)->first(['subdomain', 'settings_json']);
        if (!$website) return;
        $sub = str_replace('.levelupgrowth.io', '', (string) $website->subdomain);
        $slugs = DB::table('pages')->where('website_id', $wid)->pluck('slug')->all();
        $slugs[] = 'home';
        if ($articleId) {
            $slug = (string) DB::table('articles')->where('id', $articleId)->value('slug');
            if ($slug !== '') { $base = $this->articleBase($website); $slugs[] = "{$base}/{$slug}"; $slugs[] = "news/{$slug}"; $slugs[] = "blog/{$slug}"; }
        }
        foreach (array_unique($slugs) as $s) Cache::forget("published_site:{$sub}:{$s}");
    }

    // ─── commissions (Sarah / Priya write the story) ────────────────────────

    public function commission(int $wsId, int $wid, int $userId, array $d): array
    {
        $title = trim((string) ($d['title'] ?? '')); $brief = trim((string) ($d['brief'] ?? ''));
        if ($title === '' && $brief === '') return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Give the story a working title or a brief.'];
        $section = $this->sectionSlug($wsId, $d['section'] ?? null);
        $type = in_array($d['type'] ?? '', self::STORY_TYPES, true) ? $d['type'] : 'article';
        $len = max(300, min(2500, (int) ($d['length'] ?? 800)));
        $site = DB::table('websites')->where('id', $wid)->first();
        $region = $this->regionCode($site, $d['region'] ?? null); // QATAR-1
        $regionRow = null; foreach ($this->regions($site) as $r) if ($r['code'] === $region) $regionRow = $r;
        $editionNote = $regionRow ? " Edition: {$regionRow['name']} — write for Filipinos living there (cities, agencies, currency {$regionRow['currency']}); do not mix in other countries' rules." : ' Edition: all countries the site covers; keep country-specific facts clearly labelled.';
        $params = [
            'topic' => $brief !== '' ? $brief : $title, 'title' => $title !== '' ? $title : null, 'type' => $type,
            'audience' => (string) ($d['audience'] ?? ('Readers of ' . ($site->name ?? 'the site') . ($regionRow ? ' in ' . $regionRow['name'] : ''))),
            'tone' => (string) ($d['tone'] ?? 'clear, warm, factual'), 'brief' => trim($brief . $editionNote), 'min_words' => (int) ($len * 0.8), 'max_words' => $len,
            'target_keyword' => (string) ($d['keyword'] ?? ''), 'desk' => ['website_id' => $wid, 'section' => $section, 'region' => $region],
        ];
        $res = $this->kernel->executeAsync($wsId, 'write', 'write_article', array_filter($params, fn ($v) => $v !== null && $v !== ''), ['user_id' => $userId, 'source' => 'manual', 'agent_id' => 'priya', 'priority' => 'normal']);
        $taskId = (int) ($res['task_id'] ?? $res['task']['id'] ?? 0); $approvalId = (int) ($res['approval_id'] ?? 0);
        $status = $taskId ? 'queued' : ($approvalId ? 'awaiting_approval' : 'failed');
        $id = DB::table('desk_commissions')->insertGetId([
            'workspace_id' => $wsId, 'website_id' => $wid, 'task_id' => $taskId ?: null, 'approval_id' => $approvalId ?: null, 'title' => mb_substr($title !== '' ? $title : Str::limit($brief, 120), 0, 255),
            'brief' => $brief ?: null, 'section_slug' => $section, 'region' => $region, 'type' => $type, 'status' => $status, 'error_text' => $status === 'failed' ? mb_substr((string) ($res['error'] ?? $res['message'] ?? 'Kernel refused the task.'), 0, 1000) : null,
            'requested_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('commission.create', 'commission', $id, $title !== '' ? $title : Str::limit($brief, 80), null, ['status' => $status, 'task_id' => $taskId ?: null, 'section' => $section, 'region' => $region]);
        return ['success' => $status !== 'failed', 'id' => $id, 'status' => $status, 'task_id' => $taskId ?: null, 'approval_id' => $approvalId ?: null, 'message' => $status === 'failed' ? ($res['error'] ?? $res['message'] ?? null) : null, 'kernel' => array_intersect_key($res, array_flip(['success', 'code', 'credits_reserved', 'pending_approval']))];
    }

    public function listCommissions(int $wsId, int $wid): array
    {
        $this->reconcileCommissions($wsId, $wid);
        $rows = DB::table('desk_commissions')->where('website_id', $wid)->orderByDesc('id')->limit(50)->get();
        $taskIds = $rows->pluck('task_id')->filter()->all();
        $tasks = $taskIds ? DB::table('tasks')->whereIn('id', $taskIds)->get(['id', 'status', 'progress_message', 'error_text'])->keyBy('id') : collect();
        return ['success' => true, 'commissions' => $rows->map(fn ($c) => [
            'id' => (int) $c->id, 'title' => $c->title, 'brief' => $c->brief, 'section' => $c->section_slug, 'region' => $c->region ?? null, 'type' => $c->type, 'status' => $c->status,
            'task_id' => $c->task_id, 'task_status' => $tasks[$c->task_id]->status ?? null, 'progress' => $tasks[$c->task_id]->progress_message ?? null,
            'article_id' => $c->article_id, 'error' => $c->error_text ?: ($tasks[$c->task_id]->error_text ?? null), 'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
        ])->all()];
    }

    /** Bind finished write tasks to the site: website_id, section, desk author; mark failures. Idempotent. */
    public function reconcileCommissions(int $wsId, int $wid): int
    {
        $open = DB::table('desk_commissions')->where('website_id', $wid)->whereIn('status', ['queued', 'awaiting_approval'])->get();
        $n = 0;
        foreach ($open as $c) {
            if (!$c->task_id && $c->approval_id) {
                $ap = DB::table('approvals')->where('id', $c->approval_id)->first(['status', 'task_id']);
                if ($ap && $ap->task_id) DB::table('desk_commissions')->where('id', $c->id)->update(['task_id' => $ap->task_id, 'status' => 'queued', 'updated_at' => now()]);
                elseif ($ap && in_array($ap->status, ['rejected', 'expired'], true)) DB::table('desk_commissions')->where('id', $c->id)->update(['status' => 'failed', 'error_text' => "Approval {$ap->status}.", 'updated_at' => now()]);
                continue;
            }
            $task = $c->task_id ? Task::find($c->task_id) : null;
            if (!$task) continue;
            if (in_array($task->status, ['failed', 'cancelled'], true)) {
                DB::table('desk_commissions')->where('id', $c->id)->update(['status' => 'failed', 'error_text' => mb_substr((string) ($task->error_text ?: 'Task ' . $task->status), 0, 1000), 'updated_at' => now()]);
                continue;
            }
            if ($task->status !== 'completed') continue;
            $r = is_array($task->result_json) ? $task->result_json : (json_decode((string) $task->result_json, true) ?: []);
            $aid = (int) ($r['article_id'] ?? $r['id'] ?? $r['data']['article_id'] ?? $r['result']['article_id'] ?? 0);
            if ($aid <= 0) {
                $aid = (int) (DB::table('articles')->where('workspace_id', $wsId)->whereNull('website_id')->where('created_at', '>=', $c->created_at)->where('title', 'like', mb_substr($c->title, 0, 40) . '%')->orderBy('id')->value('id') ?: 0);
            }
            if ($aid <= 0) { DB::table('desk_commissions')->where('id', $c->id)->update(['status' => 'failed', 'error_text' => 'Task finished without an article.', 'updated_at' => now()]); continue; }
            $a = DB::table('articles')->where('id', $aid)->where('workspace_id', $wsId)->first(['id', 'website_id', 'blog_category']);
            if (!$a) continue;
            $up = ['is_marketing_blog' => 1, 'updated_at' => now()];
            if (empty($a->website_id)) $up['website_id'] = $wid;
            if ($c->section_slug && empty($a->blog_category)) $up['blog_category'] = $c->section_slug;
            DB::table('articles')->where('id', $aid)->update($up);
            $this->mergeBrief($aid, ['author' => $this->deskAuthor($wid), 'region' => $c->region ?? null, 'desk' => ['commission_id' => (int) $c->id, 'requested_by' => $c->requested_by]]);
            DB::table('desk_commissions')->where('id', $c->id)->update(['status' => 'ready', 'article_id' => $aid, 'updated_at' => now()]);
            $n++;
        }
        return $n;
    }

    // ─── jobs ───────────────────────────────────────────────────────────────

    public function listJobs(int $wsId, int $wid, array $f = []): array
    {
        $q = DB::table('job_listings')->where('workspace_id', $wsId)->where('website_id', $wid)->whereNull('deleted_at');
        if (!empty($f['status']) && $f['status'] !== 'all') $q->where('status', $f['status']);
        if (!empty($f['q'])) { $s = '%' . trim($f['q']) . '%'; $q->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('company', 'like', $s)->orWhere('city', 'like', $s)); }
        $total = (clone $q)->count(); $limit = max(1, min(100, (int) ($f['limit'] ?? 50))); $offset = max(0, (int) ($f['offset'] ?? 0));
        $rows = $q->orderByRaw("FIELD(status,'draft','published','expired','archived')")->orderByDesc('updated_at')->offset($offset)->limit($limit)->get();
        $website = DB::table('websites')->where('id', $wid)->first();
        return ['success' => true, 'jobs' => $rows->map(fn ($j) => $this->jobRow($j, $website))->all(), 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + $rows->count() < $total];
    }

    private function jobRow(object $j, object $website): array
    {
        $r = (array) $j;
        $r['benefits'] = is_string($j->benefits_json ?? null) ? (json_decode($j->benefits_json, true) ?: []) : []; unset($r['benefits_json']);
        $r['requirements'] = is_string($j->requirements ?? null) && str_starts_with(trim($j->requirements), '[') ? (json_decode($j->requirements, true) ?: []) : $j->requirements;
        $r['url'] = $j->status === 'published' ? $this->siteOrigin($website) . '/jobs/' . $j->slug : null;
        $r['days_left'] = $j->expires_at ? (int) ceil((strtotime($j->expires_at) - time()) / 86400) : null;
        return $r;
    }

    public function getJob(int $wsId, int $wid, int $id): ?array
    {
        $j = DB::table('job_listings')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first();
        return $j ? $this->jobRow($j, DB::table('websites')->where('id', $wid)->first()) : null;
    }

    public function createJob(int $wsId, int $wid, int $userId, array $d): array
    {
        unset($d['publish']);
        $d['website_id'] = $wid; $d['source'] = $d['source'] ?? 'desk'; $d['created_by'] = $userId;
        $site = DB::table('websites')->where('id', $wid)->first(); // QATAR-1: country must be one of the site's editions
        $regs = $this->regions($site); if ($regs) { $d['country'] = $this->regionCode($site, $d['country'] ?? '') ?: $this->regionFromText($site, (string) ($d['country'] ?? '')) ?: $regs[0]['code']; }
        if (isset($d['description'])) $d['description'] = $this->cleanHtml((string) $d['description']);
        $res = $this->jobs->create($wsId, $d);
        if (!empty($res['success']) && !empty($res['job_id'])) { DB::table('job_listings')->where('id', $res['job_id'])->update(['created_by' => $userId]); $res['job'] = $this->getJob($wsId, $wid, (int) $res['job_id']); $this->audit->record('job.create', 'job', (int) $res['job_id'], (string) ($d['title'] ?? ''), null, ['company' => $d['company'] ?? null, 'country' => $d['country'] ?? null, 'source' => $d['source']]); }
        return $res;
    }

    public function updateJob(int $wsId, int $wid, int $userId, int $id, array $d): array
    {
        $cur = $this->getJob($wsId, $wid, $id); if (!$cur) return ['success' => false, 'error' => 'NOT_FOUND'];
        if ($this->stale((object) $cur, $d)) return ['success' => false, 'error' => 'STALE', 'message' => 'Someone saved this listing after you opened it. Reload to see their changes, or save again to overwrite.', 'job' => $cur];
        unset($d['website_id'], $d['workspace_id'], $d['status'], $d['publish'], $d['expected_updated_at']);
        if (array_key_exists('country', $d)) { $site = DB::table('websites')->where('id', $wid)->first(); $c = $this->regionCode($site, $d['country']) ?: $this->regionFromText($site, (string) $d['country']); if ($c) $d['country'] = $c; else unset($d['country']); }
        if (isset($d['description'])) $d['description'] = $this->cleanHtml((string) $d['description']);
        $res = $this->jobs->update($wsId, $id, $d);
        if (!empty($res['success'])) { $res['job'] = $this->getJob($wsId, $wid, $id); $this->invalidate($wid); $this->audit->record('job.update', 'job', $id, (string) $cur['title'], ['title' => $cur['title'], 'status' => $cur['status']], ['fields' => array_keys($d)]); }
        return $res;
    }

    public function publishJob(int $wsId, int $wid, int $userId, int $id): array
    {
        $cur = $this->getJob($wsId, $wid, $id); if (!$cur) return ['success' => false, 'error' => 'NOT_FOUND'];
        $res = $this->jobs->publish($wsId, $id);
        if (!empty($res['success'])) { $res['job'] = $this->getJob($wsId, $wid, $id); $this->invalidate($wid); $this->audit->record('job.publish', 'job', $id, (string) $cur['title'], ['status' => $cur['status']], ['status' => 'published']); }
        return $res;
    }

    public function setJobStatus(int $wsId, int $wid, int $userId, int $id, string $status): array
    {
        if (!in_array($status, ['draft', 'expired', 'archived'], true)) return ['success' => false, 'error' => 'VALIDATION'];
        $cur = $this->getJob($wsId, $wid, $id); if (!$cur) return ['success' => false, 'error' => 'NOT_FOUND'];
        $res = $this->jobs->update($wsId, $id, ['status' => $status]);
        if (!empty($res['success'])) { $res['job'] = $this->getJob($wsId, $wid, $id); $this->invalidate($wid); $this->audit->record('job.status', 'job', $id, (string) $cur['title'], ['status' => $cur['status']], ['status' => $status]); }
        return $res;
    }

    // ─── inbox (leads from the site's forms) ────────────────────────────────

    public function listInbox(int $wsId, int $wid, array $f = []): array
    {
        $q = DB::table('leads')->where('workspace_id', $wsId)->where('website_id', $wid)->whereNull('deleted_at');
        if (!empty($f['source']) && $f['source'] !== 'all') $q->where('source', $f['source']);
        if (!empty($f['status']) && $f['status'] !== 'all') $q->where('status', $f['status']);
        if (!empty($f['q'])) { $s = '%' . trim($f['q']) . '%'; $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('email', 'like', $s)->orWhere('company', 'like', $s)); }
        $total = (clone $q)->count(); $limit = max(1, min(100, (int) ($f['limit'] ?? 50))); $offset = max(0, (int) ($f['offset'] ?? 0));
        $rows = $q->orderByRaw("FIELD(status,'new','contacted','qualified','converted','lost')")->orderByDesc('created_at')->offset($offset)->limit($limit)->get();
        $ids = $rows->pluck('id')->all();
        $acts = $ids ? DB::table('activities')->where('workspace_id', $wsId)->where('activitable_type', 'lead')->whereIn('activitable_id', $ids)->select('activitable_id', DB::raw('count(*) c'))->groupBy('activitable_id')->pluck('c', 'activitable_id')->all() : [];
        $sources = DB::table('leads')->where('workspace_id', $wsId)->where('website_id', $wid)->whereNull('deleted_at')->select('source', DB::raw('count(*) c'))->groupBy('source')->pluck('c', 'source')->all();
        return ['success' => true, 'items' => $rows->map(fn ($l) => $this->leadRow($l, (int) ($acts[$l->id] ?? 0)))->all(), 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + $rows->count() < $total, 'sources' => $sources];
    }

    private function leadRow(object $l, int $activities = 0): array
    {
        $m = is_string($l->metadata_json ?? null) ? (json_decode($l->metadata_json, true) ?: []) : [];
        return ['id' => (int) $l->id, 'name' => $l->name, 'email' => $l->email, 'phone' => $l->phone, 'company' => $l->company, 'source' => $l->source, 'status' => $l->status,
            'message' => $m['first_message'] ?? null, 'submitted_at' => $m['submitted_at'] ?? $l->created_at, 'created_at' => $l->created_at, 'last_contacted_at' => $l->last_contacted_at,
            'activities' => $activities, 'tags' => is_string($l->tags_json ?? null) ? (json_decode($l->tags_json, true) ?: []) : []];
    }

    public function getInboxItem(int $wsId, int $wid, int $id): ?array
    {
        $l = DB::table('leads')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$l) return null;
        $row = $this->leadRow($l);
        $row['timeline'] = DB::table('activities')->where('workspace_id', $wsId)->where('activitable_type', 'lead')->where('activitable_id', $id)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'type', 'subject', 'description', 'performed_by', 'created_at'])->all();
        return $row;
    }

    public function updateInboxItem(int $wsId, int $wid, int $userId, int $id, array $d): array
    {
        $l = DB::table('leads')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $id)->whereNull('deleted_at')->first(['id', 'status']);
        if (!$l) return ['success' => false, 'error' => 'NOT_FOUND'];
        $up = [];
        if (!empty($d['status']) && in_array($d['status'], ['new', 'contacted', 'qualified', 'converted', 'lost'], true) && $d['status'] !== $l->status) {
            $up['status'] = $d['status'];
            if ($d['status'] === 'contacted') $up['last_contacted_at'] = now();
            if ($d['status'] === 'converted') $up['converted_at'] = now();
        }
        if ($up) { $up['updated_at'] = now(); DB::table('leads')->where('id', $id)->update($up); }
        $note = trim((string) ($d['note'] ?? ''));
        if ($note !== '' || isset($up['status'])) {
            DB::table('activities')->insert(['workspace_id' => $wsId, 'activitable_type' => 'lead', 'activitable_id' => $id, 'type' => $note !== '' ? 'note' : 'status_change',
                'subject' => isset($up['status']) ? "Status → {$up['status']}" : 'Desk note', 'description' => $note !== '' ? mb_substr($note, 0, 2000) : null,
                'metadata_json' => json_encode(['via' => 'publisher_desk']), 'completed' => 1, 'completed_at' => now(), 'performed_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($up || $note !== '') $this->audit->record('inbox.update', 'lead', $id, null, ['status' => $l->status], ['status' => $up['status'] ?? $l->status, 'note' => $note !== '']);
        return ['success' => true, 'item' => $this->getInboxItem($wsId, $wid, $id)];
    }

    /** Turn a job_post submission into a draft listing (title/company parsed from the message when possible). */
    public function jobFromInbox(int $wsId, int $wid, int $userId, int $leadId): array
    {
        $l = DB::table('leads')->where('workspace_id', $wsId)->where('website_id', $wid)->where('id', $leadId)->whereNull('deleted_at')->first();
        if (!$l) return ['success' => false, 'error' => 'NOT_FOUND'];
        $m = is_string($l->metadata_json ?? null) ? (json_decode($l->metadata_json, true) ?: []) : [];
        $msg = (string) ($m['first_message'] ?? '');
        $grab = fn (string $k) => preg_match('/^\s*(?:' . $k . ')\s*:\s*(.+)$/im', $msg, $mm) ? trim($mm[1]) : '';
        $site = DB::table('websites')->where('id', $wid)->first();
        $country = $this->regionFromText($site, $grab('country')) ?: $this->regionFromText($site, $grab('city|location')) ?: null; // "Country: Qatar" or a city line naming the country
        $d = ['title' => $grab('(?:job )?title|role|position') ?: 'Vacancy from ' . ($l->company ?: $l->name), 'company' => $grab('company|employer') ?: ($l->company ?: (string) $l->name),
              'city' => $grab('city|location') ?: ($country === 'QA' ? 'Doha' : 'Dubai'), 'country' => $country, 'apply_email' => filter_var($l->email, FILTER_VALIDATE_EMAIL) ? $l->email : null, 'description' => nl2br(e($msg)),
              'salary_text' => $grab('salary|pay'), 'employment_type' => 'full_time', 'source' => 'employer', 'lead_id' => $leadId, 'verification_source' => 'Submitted via post-a-job form by ' . $l->email];
        $res = $this->createJob($wsId, $wid, $userId, $d);
        if (!empty($res['success'])) { DB::table('activities')->insert(['workspace_id' => $wsId, 'activitable_type' => 'lead', 'activitable_id' => $leadId, 'type' => 'note', 'subject' => 'Draft job created #' . $res['job_id'], 'completed' => 1, 'completed_at' => now(), 'performed_by' => $userId, 'created_at' => now(), 'updated_at' => now()]); $this->audit->record('inbox.to_job', 'lead', $leadId, (string) $l->email, null, ['job_id' => $res['job_id']]); }
        return $res;
    }

    // ─── members ────────────────────────────────────────────────────────────

    public function listMembers(int $wsId, int $wid): array
    {
        $ws = DB::table('workspaces')->where('id', $wsId)->first(['created_by']);
        $rows = DB::table('workspace_users as wu')->join('users as u', 'u.id', '=', 'wu.user_id')->where('wu.workspace_id', $wsId)
            ->get(['u.id', 'u.name', 'u.email', 'wu.role as workspace_role', 'wu.created_at']);
        $explicit = DB::table('desk_members')->where('website_id', $wid)->whereNotNull('user_id')->pluck('role', 'user_id')->all();
        $preassigned = DB::table('desk_members')->where('website_id', $wid)->whereNull('user_id')->get(['email', 'role', 'created_at'])->all();
        $members = $rows->map(fn ($u) => [
            'user_id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email, 'workspace_role' => $u->workspace_role,
            'desk_role' => $explicit[$u->id] ?? $this->resolveRole($wsId, $wid, (int) $u->id, $u->workspace_role), 'explicit' => isset($explicit[$u->id]),
            'is_workspace_owner' => (int) $u->id === (int) ($ws->created_by ?? 0), 'since' => $u->created_at,
        ])->all();
        $invites = [];
        try { $invites = $this->team->listPendingInvites($wsId); } catch (\Throwable) {}
        return ['success' => true, 'members' => $members, 'pending_invites' => $invites, 'preassigned' => $preassigned];
    }

    public function setMemberRole(int $wsId, int $wid, int $byUserId, int $userId, string $role): array
    {
        if (!in_array($role, self::ROLES, true)) return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Unknown role.'];
        if (!DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $userId)->exists()) return ['success' => false, 'error' => 'NOT_A_MEMBER', 'message' => 'Invite this person to the workspace first.'];
        $ownerId = (int) DB::table('workspaces')->where('id', $wsId)->value('created_by');
        if ($userId === $ownerId && $role !== 'owner') return ['success' => false, 'error' => 'OWNER_LOCKED', 'message' => 'The workspace owner is always a desk owner.'];
        $before = DB::table('desk_members')->where('website_id', $wid)->where('user_id', $userId)->value('role');
        DB::table('desk_members')->updateOrInsert(['website_id' => $wid, 'user_id' => $userId], ['workspace_id' => $wsId, 'role' => $role, 'created_by' => $byUserId, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('member.role', 'user', $userId, (string) DB::table('users')->where('id', $userId)->value('email'), ['role' => $before], ['role' => $role]);
        return ['success' => true, 'user_id' => $userId, 'role' => $role];
    }

    public function invite(int $wsId, int $wid, int $byUserId, string $email, string $role): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'VALIDATION', 'message' => 'Enter a valid email.'];
        if (!in_array($role, self::ROLES, true)) $role = 'editor';
        try { $res = $this->team->inviteMember($wsId, $byUserId, $email, 'member'); }
        catch (\Throwable $e) { return ['success' => false, 'error' => 'INVITE_FAILED', 'message' => $e->getMessage()]; }
        if (!empty($res['success']) || !empty($res['invite'])) {
            // pre-assign the desk role by email; claimed on the invitee's first desk login (claimPreassignedRole)
            DB::table('desk_members')->where('website_id', $wid)->whereNull('user_id')->where('email', $email)->delete();
            DB::table('desk_members')->insert(['workspace_id' => $wsId, 'website_id' => $wid, 'user_id' => null, 'email' => $email, 'role' => $role, 'created_by' => $byUserId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('member.invite', 'user', null, $email, null, ['role' => $role]);
        }
        return $res + ['desk_role' => $role];
    }

    // ─── RESUME888: desk view of the CV builder ─────────────────────────────

    public function resumeStats(object $website): array
    {
        if (!class_exists(\App\Engines\Resume\Services\ResumeService::class)) return ['success' => false, 'error' => 'NOT_INSTALLED'];
        $svc = app(\App\Engines\Resume\Services\ResumeService::class);
        $policy = $svc->policy($website);
        return ['success' => true, 'policy' => $policy, 'mode' => $svc->mode((int) $website->id, $policy), 'stats' => $svc->stats((int) $website->id)];
    }

    /** Owner-only: budgets + on/off switch, stored in websites.settings_json.resume (token untouched). */
    public function resumeSettings(object $website, int $userId, array $d): array
    {
        $s = $this->settings($website); $r = (array) ($s['resume'] ?? []);
        $before = ['enabled' => $r['enabled'] ?? true, 'daily_budget_usd' => $r['daily_budget_usd'] ?? null, 'monthly_budget_usd' => $r['monthly_budget_usd'] ?? null];
        if (array_key_exists('enabled', $d)) $r['enabled'] = (bool) $d['enabled'];
        if (isset($d['daily_budget_usd']) && is_numeric($d['daily_budget_usd'])) $r['daily_budget_usd'] = max(0, min(1000, (float) $d['daily_budget_usd']));
        if (isset($d['monthly_budget_usd']) && is_numeric($d['monthly_budget_usd'])) $r['monthly_budget_usd'] = max(0, min(10000, (float) $d['monthly_budget_usd']));
        $s['resume'] = $r;
        DB::table('websites')->where('id', $website->id)->update(['settings_json' => json_encode($s), 'updated_at' => now()]);
        $this->audit->record('resume.settings', 'website', (int) $website->id, null, $before, ['enabled' => $r['enabled'] ?? true, 'daily_budget_usd' => $r['daily_budget_usd'] ?? null, 'monthly_budget_usd' => $r['monthly_budget_usd'] ?? null]);
        return ['success' => true, 'policy' => app(\App\Engines\Resume\Services\ResumeService::class)->policy((object) ['settings_json' => json_encode($s)])];
    }

    // ─── Unit 2: health, sessions ───────────────────────────────────────────

    public function health(int $wsId, int $wid): array
    {
        $checks = []; $status = 'ok';
        try { DB::select('SELECT 1'); $checks['database'] = 'ok'; } catch (\Throwable $e) { $checks['database'] = 'down'; $status = 'down'; }
        $last = Cache::get('desk:publish-scheduled:last_run');
        $age = $last ? (time() - strtotime($last)) : null;
        $checks['scheduler'] = ['last_run' => $last, 'age_seconds' => $age, 'state' => $age === null ? 'unknown' : ($age > 300 ? 'stale' : 'ok')];
        if ($age !== null && $age > 300) $status = $status === 'down' ? $status : 'degraded';
        $dueScheduled = DB::table('articles')->where('website_id', $wid)->where('status', 'scheduled')->whereNull('deleted_at')->where('scheduled_at', '<=', now()->subMinutes(5))->where('scheduled_at', '>=', now()->subHours(24))->count();
        $checks['stories_overdue'] = $dueScheduled; if ($dueScheduled > 0) $status = $status === 'down' ? $status : 'degraded';
        $oldestQueued = DB::table('desk_commissions')->where('website_id', $wid)->whereIn('status', ['queued', 'awaiting_approval'])->min('created_at');
        $checks['commissions'] = ['open' => DB::table('desk_commissions')->where('website_id', $wid)->whereIn('status', ['queued', 'awaiting_approval'])->count(), 'oldest_open_minutes' => $oldestQueued ? (int) floor((time() - strtotime($oldestQueued)) / 60) : 0];
        if ($checks['commissions']['oldest_open_minutes'] > 60) $status = $status === 'down' ? $status : 'degraded';
        $checks['queue'] = ['pending_tasks' => DB::table('tasks')->where('workspace_id', $wsId)->whereIn('status', ['pending', 'queued'])->count(), 'running_tasks' => DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'running')->count(), 'failed_24h' => DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count()];
        $checks['audit'] = ['rows_24h' => DB::table('desk_audit_log')->where('website_id', $wid)->where('created_at', '>=', now()->subDay())->count()];
        $checks['site'] = ['cache_driver' => config('cache.default'), 'app_env' => config('app.env')];
        return ['success' => true, 'status' => $status, 'checks' => $checks, 'checked_at' => now()->toIso8601String()];
    }

    public function sessions(int $userId, int $currentSid): array
    {
        $rows = DB::table('sessions')->where('user_id', $userId)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->limit(50)
            ->get(['id', 'workspace_id', 'auth_via', 'ip_address', 'user_agent', 'created_at', 'expires_at']);
        return ['success' => true, 'current_session_id' => $currentSid ?: null, 'sessions' => $rows->map(fn ($s) => ['id' => (int) $s->id, 'current' => (int) $s->id === $currentSid, 'via' => $s->auth_via, 'ip' => $s->ip_address, 'user_agent' => mb_substr((string) $s->user_agent, 0, 140), 'created_at' => $s->created_at, 'expires_at' => $s->expires_at])->all()];
    }

    public function revokeOtherSessions(int $userId, int $currentSid): array
    {
        $q = DB::table('sessions')->where('user_id', $userId)->whereNull('revoked_at');
        if ($currentSid > 0) $q->where('id', '!=', $currentSid);
        $n = $q->update(['revoked_at' => now()]);
        $this->audit->record('session.revoke_others', 'user', $userId, null, null, ['revoked' => $n]);
        return ['success' => true, 'revoked' => $n];
    }

    /** Called on context load: if this user was pre-assigned a desk role by email before they existed, claim it. */
    public function claimPreassignedRole(int $wsId, int $wid, int $userId, string $email): void
    {
        $row = DB::table('desk_members')->where('website_id', $wid)->whereNull('user_id')->where('email', strtolower(trim($email)))->first();
        if (!$row) return;
        if (!DB::table('desk_members')->where('website_id', $wid)->where('user_id', $userId)->exists()) {
            DB::table('desk_members')->insert(['workspace_id' => $wsId, 'website_id' => $wid, 'user_id' => $userId, 'email' => $row->email, 'role' => $row->role, 'created_by' => $row->created_by, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('desk_members')->where('id', $row->id)->delete();
    }
}

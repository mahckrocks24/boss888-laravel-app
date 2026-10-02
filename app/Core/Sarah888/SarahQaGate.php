<?php

namespace App\Core\Sarah888;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH-QA-1 (Boss, 2026-09-06): "she just doesn't accept finished tasks without her doing QA."
 *
 * Runs at task completion. Verdicts:
 *   accepted    — reviewable work that passed alignment + metadata + provenance
 *   rejected    — reviewable work that failed a hard check (task-shaped title, off-topic for the business, broken
 *                 provenance, missing metadata)
 *   needs_owner — the alignment check could not run (runtime unavailable); never auto-accepted
 *   na          — not a reviewable deliverable (reads, publishes, infra…) — recorded, not judged
 *
 * Background: INC-0007 — Priya "finished" 31 task-titled, off-topic articles for a personal chef and they went live.
 */
final class SarahQaGate
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const NEEDS_OWNER = 'needs_owner';
    public const NA = 'na';

    /** action → deliverable kind */
    public const REVIEWABLE = [
        'write_article' => 'article', 'improve_draft' => 'article', 'rewrite_article' => 'article', 'expand_article' => 'article',
        'create_post' => 'social_post', 'social_create_post' => 'social_post', 'social_ai_post' => 'social_post', 'ai_generate_post' => 'social_post',
    ];

    /** Tests inject a resolver; production asks the runtime. fn(array $profile, array $deliverable): ?array{aligned:bool, reason:string} (null = unavailable) */
    public static $alignmentResolver = null;

    public function __construct(private RuntimeClient $runtime) {}

    public static function isReviewable(string $action): bool { return isset(self::REVIEWABLE[$action]); }

    /** @return array{verdict:string, checks:array<string,array{ok:bool,detail:string}>, reasons:string[], deliverable:?array} */
    public function review(int $wsId, string $action, array $payload, array $result, ?int $businessId = null): array
    {
        $kind = self::REVIEWABLE[$action] ?? null;
        if ($kind === null) return ['verdict' => self::NA, 'checks' => [], 'reasons' => [], 'deliverable' => null];
        $checks = []; $reasons = [];
        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        // ── Provenance: the engine says it succeeded and the row it names exists
        $deliverable = $this->loadDeliverable($wsId, $kind, $data);
        $okProv = (($result['success'] ?? true) !== false) && $deliverable !== null;
        $checks['provenance'] = ['ok' => $okProv, 'detail' => $okProv ? ($kind . ' #' . $deliverable['id'] . ' exists in workspace ' . $wsId) : 'the engine reported success but no ' . $kind . ' row exists for this task in this workspace'];
        if (!$okProv) { $reasons[] = 'No ' . str_replace('_', ' ', $kind) . ' was actually produced.'; return $this->verdict(self::REJECTED, $checks, $reasons, $deliverable); }
        // ── Title / copy shape: a task title is not a deliverable title
        $title = trim((string) ($deliverable['title'] ?? $deliverable['content'] ?? ''));
        $taskShaped = $kind === 'article' ? ArticleTopicGuard::looksLikeTask($title) : false;
        $checks['title_shape'] = ['ok' => !$taskShaped, 'detail' => $taskShaped ? ArticleTopicGuard::reason($title) : 'title reads as a real ' . str_replace('_', ' ', $kind)];
        if ($taskShaped) $reasons[] = 'The title is a task or a note, not a piece for the website: "' . mb_substr($title, 0, 60) . '".';
        // ── Metadata completeness
        foreach ($this->metadataChecks($kind, $deliverable, $payload) as $name => [$ok, $detail]) { $checks[$name] = ['ok' => $ok, 'detail' => $detail]; if (!$ok && $name !== 'featured_image') $reasons[] = $detail; } // image is generated asynchronously — noted, never a rejection
        // ── Alignment with the business
        // REPORT-0071 P0-2: the business comes from the task first (task -> payload), the workspace only when the work has none
        $__bizId = $businessId ?: ((int) ($payload['business_id'] ?? 0) ?: null);
        if ($__bizId && ! DB::table('businesses')->where('workspace_id', $wsId)->where('id', $__bizId)->exists()) $__bizId = null;   // never another workspace's business
        $profile = $__bizId ? $this->businessProfile($wsId, $__bizId) : $this->workspaceProfile($wsId, (int) ($deliverable['website_id'] ?? 0));
        $checks['business_context'] = ['ok' => true, 'detail' => $__bizId ? 'judged against business #' . $__bizId . ' (' . $profile['business_name'] . ')' : 'no business on the task - judged against the workspace'];
        $alignment = $taskShaped ? ['aligned' => false, 'reason' => 'task-shaped title'] : $this->alignment($profile, $kind, $deliverable);
        if ($alignment === null) {
            $checks['alignment'] = ['ok' => false, 'detail' => 'alignment could not be checked (runtime unavailable) — held for the owner'];
            return $this->verdict(self::NEEDS_OWNER, $checks, array_merge($reasons, ['Sarah could not verify this piece fits the business — it is held for your review.']), $deliverable);
        }
        $checks['alignment'] = ['ok' => (bool) $alignment['aligned'], 'detail' => (string) ($alignment['reason'] ?? '')];
        if (!$alignment['aligned']) $reasons[] = 'Off-topic for ' . ($profile['business_name'] ?: 'this business') . ': ' . (string) ($alignment['reason'] ?? 'does not match the business');
        return $this->verdict($reasons ? self::REJECTED : self::ACCEPTED, $checks, $reasons, $deliverable);
    }

    private function verdict(string $v, array $checks, array $reasons, ?array $d): array { return ['verdict' => $v, 'checks' => $checks, 'reasons' => array_values(array_unique($reasons)), 'deliverable' => $d ? ['kind' => $d['kind'], 'id' => $d['id'], 'title' => mb_substr((string) ($d['title'] ?? $d['content'] ?? ''), 0, 120)] : null]; }

    private function loadDeliverable(int $wsId, string $kind, array $data): ?array
    {
        if ($kind === 'article') {
            $id = (int) ($data['article_id'] ?? $data['id'] ?? 0); if ($id <= 0) return null;
            $a = DB::table('articles')->where('id', $id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            return $a ? ['kind' => 'article', 'id' => $a->id, 'website_id' => (int) ($a->website_id ?? 0), 'title' => (string) $a->title, 'content' => (string) $a->content, 'meta_title' => (string) ($a->meta_title ?? ''), 'meta_description' => (string) ($a->meta_description ?? ''), 'focus_keyword' => (string) ($a->focus_keyword ?? ''), 'word_count' => (int) ($a->word_count ?? 0), 'featured_image_url' => (string) ($a->featured_image_url ?? ''), 'slug' => (string) ($a->slug ?? '')] : null;
        }
        $id = (int) ($data['post_id'] ?? $data['id'] ?? 0); if ($id <= 0) return null;
        $p = DB::table('social_posts')->where('id', $id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        return $p ? ['kind' => 'social_post', 'id' => $p->id, 'title' => mb_substr((string) $p->content, 0, 80), 'content' => (string) $p->content, 'platform' => (string) $p->platform, 'hashtags' => json_decode((string) ($p->hashtags_json ?? '[]'), true) ?: []] : null;
    }

    /** @return array<string, array{0:bool,1:string}> */
    private function metadataChecks(string $kind, array $d, array $payload): array
    {
        $c = [];
        if ($kind === 'article') {
            $wc = (int) $d['word_count']; $requested = (int) ($payload['length'] ?? $payload['word_count'] ?? 0); $min = $requested > 0 ? (int) floor($requested * 0.6) : 300;
            $c['word_count'] = [$wc >= $min, $wc >= $min ? "{$wc} words" : "Only {$wc} words (expected at least {$min})."];
            $mt = trim($d['meta_title']); $c['meta_title'] = [$mt !== '' && mb_strlen($mt) <= 70, $mt === '' ? 'Meta title is missing.' : (mb_strlen($mt) > 70 ? 'Meta title is too long (' . mb_strlen($mt) . ' chars).' : 'meta title ok')];
            $md = trim($d['meta_description']); $c['meta_description'] = [$md !== '' && mb_strlen($md) >= 50 && mb_strlen($md) <= 170, $md === '' ? 'Meta description is missing.' : ((mb_strlen($md) < 50 || mb_strlen($md) > 170) ? 'Meta description length is off (' . mb_strlen($md) . ' chars).' : 'meta description ok')];
            $c['focus_keyword'] = [trim($d['focus_keyword']) !== '', trim($d['focus_keyword']) !== '' ? 'focus keyword set' : 'Focus keyword is missing.'];
            $c['slug'] = [trim($d['slug']) !== '' && !ArticleTopicGuard::looksLikeTask(str_replace('-', ' ', $d['slug'])), trim($d['slug']) !== '' ? 'slug ok' : 'Slug is missing.'];
            $c['featured_image'] = [trim($d['featured_image_url']) !== '', trim($d['featured_image_url']) !== '' ? 'featured image attached' : 'No featured image yet.'];
        } else {
            $len = mb_strlen(trim($d['content'])); $limits = ['twitter' => 280, 'instagram' => 2200, 'linkedin' => 3000, 'facebook' => 63206, 'tiktok' => 2200, 'snapchat' => 250]; $lim = $limits[$d['platform']] ?? 3000;
            $c['content'] = [$len >= 20, $len >= 20 ? "{$len} chars" : 'Post copy is empty or too short.'];
            $c['platform_limit'] = [$len <= $lim, $len <= $lim ? 'within ' . $d['platform'] . ' limit' : "Too long for {$d['platform']} ({$len} > {$lim})."];
            $c['hashtags'] = [count($d['hashtags']) <= 30, count($d['hashtags']) <= 30 ? count($d['hashtags']) . ' hashtags' : 'Too many hashtags (' . count($d['hashtags']) . ').'];
        }
        return $c;
    }

    /**
     * The business(es) this workspace publishes for. `businesses` lists every distinct website business (name/industry/
     * description) — a multi-site workspace (INC-0006) is judged against ALL of them; when the deliverable is attached
     * to one website, only that site. workspace_memory (the owner's own strategy answers) always comes first.
     * @return array{business_name:string, industry:string, services:string, audience:string, location:string, description:string, businesses:array<int,array{name:string,industry:string,description:string}>}
     */
    public function workspaceProfile(int $wsId, int $websiteId = 0): array
    {
        $p = ['business_name' => '', 'industry' => '', 'services' => '', 'audience' => '', 'location' => '', 'description' => '', 'businesses' => []];
        try {
            $mem = DB::table('workspace_memory')->where('workspace_id', $wsId)->whereIn('key', ['business_name', 'industry', 'services', 'target_audience', 'location', 'differentiators', 'business_description'])->pluck('value_json', 'key');
            $val = function ($k) use ($mem) { if (!isset($mem[$k])) return ''; $j = json_decode((string) $mem[$k], true); if (is_string($j)) return trim($j); if (is_array($j)) return trim(implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $j))); return trim((string) $mem[$k]); };
            $p['business_name'] = $val('business_name'); $p['industry'] = $val('industry'); $p['services'] = $val('services'); $p['audience'] = $val('target_audience'); $p['location'] = $val('location'); $p['description'] = trim($val('business_description') . ' ' . $val('differentiators'));
        } catch (\Throwable) {}
        try {
            $q = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at'); if ($websiteId > 0) $q->where('id', $websiteId);
            $seen = [];
            foreach ($q->orderByRaw("status = 'published' desc")->orderByDesc('id')->get(['id', 'name', 'template_industry', 'template_variables']) as $w) { // newest first: the current business, not template leftovers
                $tv = json_decode((string) $w->template_variables, true) ?: [];
                $b = ['name' => trim((string) ($tv['business_name'] ?? $w->name ?? '')), 'industry' => trim((string) ($tv['industry'] ?? $w->template_industry ?? '')), 'description' => trim((string) ($tv['tagline'] ?? $tv['hero_subtitle'] ?? $tv['about_text'] ?? ''))];
                if ($b['name'] === '' && $b['industry'] === '') continue;
                $k = strtolower($b['name'] . '|' . $b['industry']); if (isset($seen[$k])) continue; $seen[$k] = true; $p['businesses'][] = $b;
            }
        } catch (\Throwable) {}
        try {   // REPORT-0071 P0-2: a business without a website is still a business of this workspace
            $__seen = array_flip(array_map(fn ($b) => strtolower($b['name']), $p['businesses']));
            foreach (DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['name', 'industry', 'differentiators', 'target_audience']) as $__b) {
                if (isset($__seen[strtolower((string) $__b->name)])) continue;
                $p['businesses'][] = ['name' => (string) $__b->name, 'industry' => str_replace('_', ' ', (string) $__b->industry), 'description' => trim((string) $__b->differentiators . ' ' . (string) $__b->target_audience)];
            }
        } catch (\Throwable) {}
        if ($p['business_name'] === '' && $p['businesses']) $p['business_name'] = $p['businesses'][0]['name'];
        if ($p['industry'] === '' && $p['businesses']) $p['industry'] = $p['businesses'][0]['industry'];
        if ($p['business_name'] === '') $p['business_name'] = (string) (DB::table('workspaces')->where('id', $wsId)->value('name') ?? '');
        return $p;
    }
    /**
     * REPORT-0071 P0-2: one business's own context, website optional. Identity comes from the business record (industry, services,
     * audience, differentiators, tone, location), its brand profile, what the owner told Sarah about it (journal, confirmed facts)
     * and - only if it has one - its website. `businesses` holds this business alone, so a piece that fits a DIFFERENT business
     * of the same workspace is not aligned (no cross-business pass).
     */
    public function businessProfile(int $wsId, int $bizId): array
    {
        $b = DB::table('businesses')->where('workspace_id', $wsId)->where('id', $bizId)->first();
        if (! $b) return $this->workspaceProfile($wsId);
        $svc = json_decode((string) ($b->services_json ?? ''), true); $svc = is_array($svc) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : (string) ($x['name'] ?? ''), $svc)) : '';
        $about = [trim((string) ($b->differentiators ?? '')), trim((string) ($b->goal ?? ''))];
        try { $br = DB::table('creative_brand_identities')->where('workspace_id', $wsId)->where('business_id', $bizId)->whereNull('deleted_at')->orderByDesc('id')->first(['voice', 'tone', 'visual_style', 'style_notes']);
              if ($br) $about[] = trim(implode(' ', array_filter([(string) $br->voice, (string) $br->tone, (string) $br->style_notes]))); } catch (\Throwable) {}
        try { foreach (DB::table('business_journal')->where('workspace_id', $wsId)->where('business_id', $bizId)->orderByDesc('id')->limit(5)->pluck('text') as $j) $about[] = (string) $j; } catch (\Throwable) {}
        try { foreach (DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('business_id', $bizId)->where('status', 'confirmed')->whereIn('group', ['identity', 'interests'])->limit(5)->pluck('value') as $f) $about[] = (string) $f; } catch (\Throwable) {}
        try { foreach (DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $bizId)->whereNull('deleted_at')->limit(2)->get(['template_variables']) as $w) { $tv = json_decode((string) $w->template_variables, true) ?: []; $about[] = trim((string) ($tv['tagline'] ?? $tv['hero_subtitle'] ?? '')); } } catch (\Throwable) {}
        $desc = mb_substr(trim(implode(' ', array_filter($about))), 0, 900);
        $industry = str_replace('_', ' ', (string) ($b->industry ?? ''));
        return ['business_name' => (string) $b->name, 'industry' => $industry, 'services' => $svc, 'audience' => (string) ($b->target_audience ?? ''), 'location' => (string) ($b->location ?? ''),
                'description' => $desc, 'scope' => 'business', 'business_id' => $bizId, 'businesses' => [['name' => (string) $b->name, 'industry' => $industry, 'description' => $desc]]];
    }

    /** @return ?array{aligned:bool, reason:string} null when the check could not run */
    private function alignment(array $profile, string $kind, array $d): ?array
    {
        if (is_callable(self::$alignmentResolver)) return (self::$alignmentResolver)($profile, $d);
        if (trim($profile['industry'] . $profile['services'] . $profile['description']) === '' && empty($profile['businesses'])) return ['aligned' => true, 'reason' => 'no business profile to judge against — accepted on metadata only'];
        if (!$this->runtime->isConfigured()) return null;
        $biz = []; foreach (array_slice($profile['businesses'] ?? [], 0, 30) as $b) $biz[] = $b['name'] . ($b['industry'] !== '' ? ' (' . $b['industry'] . ')' : '') . ($b['description'] !== '' ? ' — ' . mb_substr($b['description'], 0, 80) : '');
        $system = "You are Sarah, the AI marketing director doing quality control on a specialist's finished work for {$profile['business_name']}"
            . ($profile['industry'] !== '' ? ", a {$profile['industry']} business" : '') . ($profile['location'] !== '' ? " in {$profile['location']}" : '') . '.'
            . ($profile['services'] !== '' ? " Services: {$profile['services']}." : '') . ($profile['audience'] !== '' ? " Audience: {$profile['audience']}." : '') . ($profile['description'] !== '' ? " About: " . mb_substr($profile['description'], 0, 300) . '.' : '')
            . (($profile['scope'] ?? '') === 'business' ? "\nThis piece was made for THIS business only. It is aligned only if it fits THIS business; a piece that would suit a different business is NOT aligned. A business needs no website: judge it from the profile above." : '')
            . (($profile['scope'] ?? '') !== 'business' && count($biz) > 1 ? "\nThis account publishes for SEVERAL businesses/websites: " . implode('; ', $biz) . '. The piece is aligned if it fits ANY of them.' : '')
            . "\nDecide whether this piece is something one of these businesses would publish for ITS customers: same industry, its own services or expertise, its audience. Internal notes, project chatter, other industries, generic software/DevOps/marketing-ops content for a non-software business are NOT aligned."
            . "\nWhen genuinely borderline but plausibly useful to its customers, answer aligned=true."
            . "\nReturn ONLY JSON: {\"aligned\": true|false, \"reason\": \"one short sentence\"}";
        $body = mb_substr(trim(strip_tags((string) ($d['content'] ?? ''))), 0, 1200);
        $user = "Deliverable ({$kind}) title: \"" . ($d['title'] ?? '') . "\"\nExcerpt: " . $body;
        try {
            $r = $this->runtime->chatJson($system, $user, ['task' => 'sarah_qa_alignment'], 200);
            if (!($r['success'] ?? false)) return null;
            $p = $r['parsed'] ?? null; if (!is_array($p)) { $p = json_decode((string) ($r['text'] ?? ''), true); }
            if (!is_array($p) || !array_key_exists('aligned', $p)) return null;
            return ['aligned' => (bool) $p['aligned'], 'reason' => trim((string) ($p['reason'] ?? ''))];
        } catch (\Throwable $e) { Log::warning('[SarahQA] alignment check failed', ['error' => $e->getMessage()]); return null; }
    }

    /** Owner-facing one-liner. */
    public static function summary(array $qa, string $agentName, string $what): string
    {
        return match ($qa['verdict'] ?? '') {
            self::ACCEPTED => "{$agentName} finished {$what} — Sarah checked it (fit, metadata, provenance) and accepted it.",
            self::REJECTED => "{$agentName} finished {$what}, but Sarah REJECTED it in QA: " . implode(' ', $qa['reasons'] ?? []) . ' It stays a draft and will not be published until it is fixed.' . (! empty($qa['refund']['credits']) ? ' The ' . (int) $qa['refund']['credits'] . ' credits it cost were returned.' : ''),
            self::NEEDS_OWNER => "{$agentName} finished {$what} — Sarah could not fully verify it and is holding it for your review: " . implode(' ', $qa['reasons'] ?? []),
            default => "{$agentName} finished {$what}.",
        };
    }
}

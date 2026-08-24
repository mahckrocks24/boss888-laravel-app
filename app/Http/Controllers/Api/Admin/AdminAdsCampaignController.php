<?php

namespace App\Http\Controllers\Api\Admin;

use App\Engines\Ads\Services\AdAuditService;
use App\Engines\Ads\Services\AdCreativeValidator;
use App\Engines\Ads\Services\AdReachEstimator;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Support\AdSettings;
use App\Engines\Ads\Support\AdIndustryTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADS888 admin API — phase A3: advertisers, campaigns, creatives.
 *
 * This is the capability with no CLI equivalent, and the one an ad-ops person
 * uses daily.
 *
 * RULES THIS CONTROLLER ENFORCES (not the UI — the UI can be bypassed)
 *   - Nothing serves unapproved. Creatives are created `pending` and only an
 *     explicit, MFA-stepped-up approval moves them to `approved`.
 *   - Creative specs are validated by AdCreativeValidator, which REJECTS rather
 *     than silently corrects: a cropped creative or a trimmed video is an
 *     advertiser dispute.
 *   - A blocked category cannot be assigned to an advertiser at all.
 *   - Every mutation writes ad_audit_log with the acting user, so
 *     "who approved this" is always answerable.
 *
 * UPLOADS ARE HOSTILE INPUT. Assets are stored under a generated name in a
 * public disk path with an extension whitelist; the original filename is never
 * used on disk and HTML/SVG are not accepted.
 */
class AdminAdsCampaignController
{
    /** Image types we will serve. SVG is excluded deliberately — it executes. */
    private const IMAGE_MIMES = 'jpeg,jpg,png,webp,gif';
    private const VIDEO_MIMES = 'mp4,webm';

    public function __construct(
        private readonly AdCreativeValidator $validator,
        private readonly AdAuditService $audit,
        private readonly AdSettingsService $settings,
        private readonly AdReachEstimator $reach,
    ) {
    }

    // ── Advertisers ─────────────────────────────────────────────────────

    public function listAdvertisers(): JsonResponse
    {
        $rows = DB::table('advertisers as a')
            ->leftJoin('ad_campaigns as c', 'c.advertiser_id', '=', 'a.id')
            ->groupBy('a.id')
            ->orderBy('a.name')
            ->get([
                'a.id', 'a.name', 'a.status', 'a.category', 'a.contact_email',
                'a.click_domain', 'a.is_house',
                DB::raw('COUNT(c.id) as campaign_count'),
            ]);

        return response()->json(['advertisers' => $rows]);
    }

    public function createAdvertiser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:191',
            'legal_name'    => 'nullable|string|max:191',
            'contact_email' => 'nullable|email|max:191',
            'category'      => 'nullable|string|max:64',
            'click_domain'  => 'nullable|string|max:191',
            'notes'         => 'nullable|string|max:2000',
        ]);

        if ($blocked = $this->blockedCategory($data['category'] ?? null)) {
            return response()->json(['error' => $blocked], 422);
        }

        $id = DB::table('advertisers')->insertGetId($data + [
            'status'     => 'active',
            'is_house'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->log($request, 'advertiser.create', 'advertiser', $id, null, $data);

        return response()->json(['id' => $id] + $data, 201);
    }

    public function updateAdvertiser(Request $request, int $id): JsonResponse
    {
        $before = DB::table('advertisers')->where('id', $id)->first();

        if (! $before) {
            return response()->json(['error' => 'advertiser not found'], 404);
        }

        $data = $request->validate([
            'name'          => 'sometimes|string|max:191',
            'contact_email' => 'nullable|email|max:191',
            'category'      => 'nullable|string|max:64',
            'click_domain'  => 'nullable|string|max:191',
            'status'        => 'sometimes|in:active,paused,blocked',
            'notes'         => 'nullable|string|max:2000',
        ]);

        if ($blocked = $this->blockedCategory($data['category'] ?? null)) {
            return response()->json(['error' => $blocked], 422);
        }

        DB::table('advertisers')->where('id', $id)->update($data + ['updated_at' => now()]);

        $this->log($request, 'advertiser.update', 'advertiser', $id, (array) $before, $data);

        return response()->json(['ok' => true]);
    }

    // ── Campaigns ───────────────────────────────────────────────────────

    public function listCampaigns(Request $request): JsonResponse
    {
        $rows = DB::table('ad_campaigns as c')
            ->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')
            ->leftJoin('ad_creatives as cr', 'cr.campaign_id', '=', 'c.id')
            ->when($request->query('status'), fn ($q, $s) => $q->where('c.status', $s))
            ->groupBy('c.id', 'a.name')
            ->orderByDesc('c.id')
            ->limit(200)
            ->get([
                'c.id', 'c.name', 'c.kind', 'c.status', 'c.pricing_model', 'c.rate_micros',
                'c.budget_total_micros', 'c.priority_tier', 'c.starts_at', 'c.ends_at',
                'a.name as advertiser',
                DB::raw('COUNT(cr.id) as creative_count'),
                DB::raw("SUM(CASE WHEN cr.review_state = 'approved' THEN 1 ELSE 0 END) as approved_count"),
            ]);

        return response()->json(['campaigns' => $rows]);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'advertiser_id'       => 'required|integer|exists:advertisers,id',
            'name'                => 'required|string|max:191',
            'kind'                => 'required|in:paid,house',
            'pricing_model'       => 'required|in:cpm,cpc,cpcv,flat',
            'rate_micros'         => 'required|integer|min:0',
            'budget_total_micros' => 'nullable|integer|min:0',
            'budget_daily_micros' => 'nullable|integer|min:0',
            'pacing'              => 'nullable|in:even,asap',
            'priority_tier'       => 'nullable|integer|min:1|max:9',
            'starts_at'           => 'nullable|date',
            'ends_at'             => 'nullable|date|after_or_equal:starts_at',
            'targeting'           => 'nullable|array',
            'targeting.*.dimension' => 'required_with:targeting|string|max:32',
            'targeting.*.operator'  => 'nullable|in:in,not_in,gte',
            'targeting.*.values'    => 'required_with:targeting|array',
        ]);

        $targeting = $data['targeting'] ?? [];
        unset($data['targeting']);

        // A house campaign is uncapped by definition — that is what guarantees
        // 100% fill and a slot that is never blank.
        if ($data['kind'] === 'house') {
            $data['budget_total_micros'] = null;
            $data['budget_daily_micros'] = null;
            $data['priority_tier'] = $data['priority_tier'] ?? 5;
        } else {
            $data['priority_tier'] = $data['priority_tier'] ?? 1;
        }

        $id = DB::table('ad_campaigns')->insertGetId($data + [
            'status'     => 'draft',          // never live on creation
            'pacing'     => $data['pacing'] ?? 'even',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($targeting as $rule) {
            DB::table('ad_targeting')->insert([
                'campaign_id' => $id,
                'dimension'   => $rule['dimension'],
                'operator'    => $rule['operator'] ?? 'in',
                'values'      => json_encode(array_values($rule['values'])),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $this->log($request, 'campaign.create', 'campaign', $id, null, $data + ['targeting' => $targeting]);

        return response()->json(['id' => $id, 'status' => 'draft'], 201);
    }

    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        $before = DB::table('ad_campaigns')->where('id', $id)->first();

        if (! $before) {
            return response()->json(['error' => 'campaign not found'], 404);
        }

        $data = $request->validate([
            'name'                => 'sometimes|string|max:191',
            'status'              => 'sometimes|in:draft,pending,active,paused,completed,rejected',
            'rate_micros'         => 'sometimes|integer|min:0',
            'budget_total_micros' => 'nullable|integer|min:0',
            'budget_daily_micros' => 'nullable|integer|min:0',
            'pacing'              => 'sometimes|in:even,asap',
            'priority_tier'       => 'sometimes|integer|min:1|max:9',
            'starts_at'           => 'nullable|date',
            'ends_at'             => 'nullable|date',
        ]);

        // Refuse to activate a campaign that cannot actually serve — otherwise
        // it sits "active" delivering nothing and nobody knows why.
        if (($data['status'] ?? null) === 'active') {
            $approved = DB::table('ad_creatives')
                ->where('campaign_id', $id)->where('review_state', 'approved')->count();

            if ($approved === 0) {
                return response()->json([
                    'error' => 'This campaign has no APPROVED creative, so activating it would deliver nothing. '
                             . 'Approve a creative first.',
                ], 422);
            }
        }

        DB::table('ad_campaigns')->where('id', $id)->update($data + ['updated_at' => now()]);

        $this->log($request, 'campaign.update', 'campaign', $id, (array) $before, $data);

        return response()->json(['ok' => true]);
    }

    public function campaignTargeting(int $id): JsonResponse
    {
        $rows = DB::table('ad_targeting')->where('campaign_id', $id)->get();

        return response()->json([
            'targeting' => $rows->map(fn ($r) => [
                'id'        => $r->id,
                'dimension' => $r->dimension,
                'operator'  => $r->operator,
                'values'    => json_decode((string) $r->values, true) ?: [],
            ])->all(),
            'available_dimensions' => \App\Engines\Ads\Services\AdTargetingMatcher::supportedDimensions(),
            'industries'           => AdIndustryTaxonomy::INDUSTRIES,
            'archetypes'           => AdIndustryTaxonomy::ARCHETYPES,
        ]);
    }

    /**
     * POST /admin/ads/reach — run the estimator against a proposed targeting
     * spec BEFORE the campaign is sold. It refuses to quote thin inventory, and
     * that refusal is the whole point.
     */
    public function reach(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targeting'             => 'nullable|array',
            'targeting.*.dimension' => 'required_with:targeting|string|max:32',
            'targeting.*.values'    => 'required_with:targeting|array',
        ]);

        return response()->json($this->reach->estimate($data['targeting'] ?? []));
    }

    // ── Creatives ───────────────────────────────────────────────────────

    public function listCreatives(Request $request): JsonResponse
    {
        $rows = DB::table('ad_creatives as cr')
            ->join('ad_campaigns as c', 'c.id', '=', 'cr.campaign_id')
            ->join('ad_slots as s', 's.id', '=', 'cr.slot_id')
            ->when($request->query('review_state'), fn ($q, $v) => $q->where('cr.review_state', $v))
            ->when($request->query('campaign_id'), fn ($q, $v) => $q->where('cr.campaign_id', (int) $v))
            ->orderByDesc('cr.id')
            ->limit(200)
            ->get([
                'cr.id', 'cr.campaign_id', 'cr.slot_id', 'cr.type', 'cr.media_type',
                'cr.asset_url', 'cr.video_url', 'cr.poster_url', 'cr.click_url', 'cr.alt_text',
                'cr.aspect_ratio', 'cr.width', 'cr.height', 'cr.duration_ms',
                'cr.review_state', 'cr.review_note', 'cr.reviewed_at',
                'c.name as campaign', 'c.kind', 's.code as slot',
            ]);

        return response()->json(['creatives' => $rows]);
    }

    public function uploadCreative(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => 'required|integer|exists:ad_campaigns,id',
            'slot_id'     => 'required|integer|exists:ad_slots,id',
            'click_url'   => 'required|url|max:512',
            'alt_text'    => 'nullable|string|max:255',
            'weight'      => 'nullable|integer|min:1|max:1000',
            'asset'       => 'required|file|mimes:' . self::IMAGE_MIMES . ',' . self::VIDEO_MIMES . '|max:10240',
            'poster'      => 'nullable|file|mimes:' . self::IMAGE_MIMES . '|max:2048',
            'duration_ms' => 'nullable|integer|min:1',
        ]);

        $file      = $request->file('asset');
        $ext       = strtolower($file->getClientOriginalExtension());
        $isVideo   = in_array($ext, explode(',', self::VIDEO_MIMES), true);

        if ($isVideo && ! $this->settings->bool(AdSettings::MODAL_ALLOW_VIDEO)) {
            return response()->json([
                'error' => 'Video creatives are disabled (modal_allow_video). Enable it deliberately '
                         . 'once bandwidth cost per completed view has been modelled.',
            ], 422);
        }

        // Dimensions must come from the FILE, never from the client — a
        // client-declared size would let anyone bypass the ratio spec.
        [$width, $height] = $this->measure($file, $isVideo, $request);

        $poster = $request->file('poster');
        $posterUrl = $poster ? $this->store($poster, 'poster') : null;

        $candidate = [
            'media_type'  => $isVideo ? 'video' : 'image',
            'width'       => $width,
            'height'      => $height,
            'duration_ms' => $data['duration_ms'] ?? null,
            'poster_url'  => $posterUrl,
            'video_url'   => $isVideo ? 'pending' : null,
            'asset_url'   => $isVideo ? null : 'pending',
            'file_bytes'  => $file->getSize(),
        ];

        $slot   = DB::table('ad_slots')->where('id', $data['slot_id'])->first();
        $ratios = $slot && $slot->allowed_ratios ? json_decode((string) $slot->allowed_ratios, true) : null;

        $check = $this->validator->validate($candidate, is_array($ratios) ? $ratios : null);

        if (! $check['valid']) {
            // Reject, never silently correct.
            return response()->json(['error' => 'Creative rejected', 'reasons' => $check['errors']], 422);
        }

        $storedUrl = $this->store($file, $isVideo ? 'video' : 'image');

        $id = DB::table('ad_creatives')->insertGetId([
            'campaign_id'  => $data['campaign_id'],
            'slot_id'      => $data['slot_id'],
            'type'         => $isVideo ? 'video' : 'image',
            'media_type'   => $isVideo ? 'video' : 'image',
            'asset_url'    => $isVideo ? null : $storedUrl,
            'video_url'    => $isVideo ? $storedUrl : null,
            'poster_url'   => $posterUrl,
            'click_url'    => $data['click_url'],
            'alt_text'     => $data['alt_text'] ?? null,
            'weight'       => $data['weight'] ?? 100,
            'aspect_ratio' => $check['ratio'],
            'width'        => $width,
            'height'       => $height,
            'duration_ms'  => $data['duration_ms'] ?? null,
            'file_bytes'   => $file->getSize(),
            'review_state' => 'pending',      // NOTHING serves unapproved
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->log($request, 'creative.upload', 'creative', $id, null, [
            'campaign_id' => $data['campaign_id'],
            'ratio'       => $check['ratio'],
            'dimensions'  => $width . 'x' . $height,
            'media_type'  => $isVideo ? 'video' : 'image',
        ]);

        return response()->json([
            'id' => $id, 'aspect_ratio' => $check['ratio'],
            'width' => $width, 'height' => $height,
            'review_state' => 'pending',
            'note' => 'Uploaded and awaiting review. Nothing serves until it is approved.',
        ], 201);
    }

    /** POST /admin/ads/creatives/{id}/approve — behind MFA step-up. */
    public function approveCreative(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'approved');
    }

    /** POST /admin/ads/creatives/{id}/reject — behind MFA step-up. */
    public function rejectCreative(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'rejected');
    }

    private function review(Request $request, int $id, string $state): JsonResponse
    {
        $data = $request->validate([
            'note' => ($state === 'rejected' ? 'required' : 'nullable') . '|string|max:255',
        ]);

        $before = DB::table('ad_creatives')->where('id', $id)->first();

        if (! $before) {
            return response()->json(['error' => 'creative not found'], 404);
        }

        DB::table('ad_creatives')->where('id', $id)->update([
            'review_state' => $state,
            'review_note'  => $data['note'] ?? null,
            'reviewed_by'  => $request->user()?->id,
            'reviewed_at'  => now(),
            'updated_at'   => now(),
        ]);

        $this->log($request, 'creative.' . $state, 'creative', $id,
            ['review_state' => $before->review_state],
            ['review_state' => $state, 'note' => $data['note'] ?? null]);

        return response()->json(['ok' => true, 'review_state' => $state]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function blockedCategory(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        $blocked = array_map('strtolower', $this->settings->array(AdSettings::CATEGORY_BLOCKLIST));

        return in_array(strtolower($category), $blocked, true)
            ? "Category '{$category}' is on the blocklist and cannot be onboarded."
            : null;
    }

    /** @return array{0:int,1:int} */
    private function measure($file, bool $isVideo, Request $request): array
    {
        if (! $isVideo) {
            $size = @getimagesize($file->getRealPath());

            return [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
        }

        // Video dimensions need a probe we do not have here; the uploader
        // declares them and the validator still enforces the ratio and floor.
        return [
            (int) $request->input('width', 0),
            (int) $request->input('height', 0),
        ];
    }

    /**
     * Store under a GENERATED name. The client filename never touches the disk,
     * and the extension comes from our own whitelist — an uploaded
     * "logo.php.png" cannot become executable.
     */
    private function store($file, string $kind): string
    {
        $ext  = strtolower($file->getClientOriginalExtension());
        $name = $kind . '-' . Str::uuid()->toString() . '.' . preg_replace('/[^a-z0-9]/', '', $ext);

        $file->storeAs('public/ad-creatives', $name);

        return '/storage/ad-creatives/' . $name;
    }

    private function log(Request $request, string $action, string $type, ?int $id, ?array $before, ?array $after): void
    {
        try {
            $this->audit->record(
                action: $action,
                subjectType: $type,
                subjectId: $id,
                before: $before,
                after: $after,
                actorId: $request->user()?->id,
                actorLabel: $request->user()?->email,
            );
        } catch (Throwable) {
            // Auditing must never block the action it audits.
        }
    }
}

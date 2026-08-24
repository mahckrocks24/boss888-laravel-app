<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADS888 P0 — seed the ad platform's supply side and house demand.
 *
 * Idempotent: safe to run repeatedly. Existing rows are left alone rather than
 * overwritten, so an operator's slot or campaign edits survive a re-run.
 *
 * WHAT IT SEEDS
 *   - 3 slot definitions. Only `footer_sticky` is ACTIVE (verified against all
 *     31 templates). The other two are defined-but-disabled so enabling them is
 *     an admin toggle, not a deploy.
 *   - Slot eligibility exclusions from the structural audit: news_channel has no
 *     data-block attributes and different footer markup, so it is excluded from
 *     in_content_mrec and footer_leaderboard.
 *   - The house advertiser + a house upgrade campaign at priority tier 5, with
 *     no budget cap, so fill rate is 100% from the first page view.
 *
 * The house creative ships review_state='pending' ON PURPOSE. Nothing serves
 * unapproved — including our own ads. Approving it is a deliberate act:
 *   php artisan ads:seed-platform --approve-house
 */
class AdsSeedPlatformCommand extends Command
{
    protected $signature = 'ads:seed-platform
        {--approve-house : Also approve the house creative so it can serve}
        {--dry-run : Report what would be seeded without writing}';

    protected $description = 'ADS888: seed ad slots, eligibility exclusions and the house campaign';

    /** Slot definitions — verified against the structural audit of all 31 templates. */
    private const SLOTS = [
        [
            'code' => 'footer_sticky', 'name' => 'Footer sticky bar',
            'format' => 'mobile_banner', 'width' => 320, 'height' => 50,
            'position' => 'footer_sticky', 'device' => 'all',
            'is_active' => true, 'sort_order' => 0,
        ],
        [
            'code' => 'footer_leaderboard', 'name' => 'Footer leaderboard',
            'format' => 'leaderboard', 'width' => 728, 'height' => 90,
            'position' => 'before_footer', 'device' => 'desktop',
            'is_active' => false, 'sort_order' => 1,
        ],
        [
            'code' => 'in_content_mrec', 'name' => 'In-content MREC',
            'format' => 'mrec', 'width' => 300, 'height' => 250,
            'position' => 'in_content', 'device' => 'all',
            'is_active' => false, 'sort_order' => 2,
        ],
        [
            // Session interstitial. Ships INACTIVE and additionally gated behind
            // its own `modal_enabled` setting, so it takes two deliberate acts to
            // put a full-screen ad on anyone's website.
            'code' => 'interstitial_modal', 'name' => 'Session interstitial',
            'format' => 'modal', 'width' => 1080, 'height' => 1080,
            'position' => 'modal', 'device' => 'all',
            'is_active' => false, 'sort_order' => 3,
            'allowed_ratios' => '["1:1","3:4"]', 'is_interstitial' => true,
        ],
    ];

    /** From the structural audit — templates a slot must not appear on. */
    private const EXCLUSIONS = [
        'in_content_mrec'    => ['news_channel' => 'template has no data-block attributes to anchor to'],
        'footer_leaderboard' => ['news_channel' => 'footer uses class="footer" data-section, not data-block'],
    ];

    public function handle(AdSettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->info('ADS888 — platform seed' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line(str_repeat('─', 72));

        // ── Slots ───────────────────────────────────────────────────────
        $slotIds = [];
        foreach (self::SLOTS as $slot) {
            $existing = DB::table('ad_slots')->where('code', $slot['code'])->first();

            if ($existing) {
                $slotIds[$slot['code']] = (int) $existing->id;
                $this->line("  slot  {$slot['code']}  — exists, left unchanged");
                continue;
            }

            if ($dryRun) {
                $this->line("  slot  {$slot['code']}  — would create (active=" . ($slot['is_active'] ? 'yes' : 'no') . ')');
                continue;
            }

            $slotIds[$slot['code']] = (int) DB::table('ad_slots')->insertGetId(
                $slot + ['created_at' => now(), 'updated_at' => now()]
            );
            $this->line("  slot  {$slot['code']}  — created (active=" . ($slot['is_active'] ? 'yes' : 'no') . ')');
        }

        // ── Eligibility exclusions ──────────────────────────────────────
        foreach (self::EXCLUSIONS as $slotCode => $exclusions) {
            $slotId = $slotIds[$slotCode] ?? null;
            if ($slotId === null) {
                continue;
            }

            foreach ($exclusions as $industry => $reason) {
                $exists = DB::table('ad_slot_eligibility')
                    ->where('slot_id', $slotId)->where('template_industry', $industry)->exists();

                if ($exists) {
                    $this->line("  excl  {$slotCode} × {$industry}  — exists");
                    continue;
                }

                if ($dryRun) {
                    $this->line("  excl  {$slotCode} × {$industry}  — would create");
                    continue;
                }

                DB::table('ad_slot_eligibility')->insert([
                    'slot_id' => $slotId, 'template_industry' => $industry,
                    'enabled' => false, 'reason' => $reason,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->line("  excl  {$slotCode} × {$industry}  — created");
            }
        }

        // ── House advertiser + campaign + creative ──────────────────────
        if (! $dryRun) {
            $houseId = DB::table('advertisers')->where('is_house', true)->value('id');

            if (! $houseId) {
                $houseId = DB::table('advertisers')->insertGetId([
                    'name' => 'LevelUp Growth (House)', 'legal_name' => 'LevelUp Growth',
                    'status' => 'active', 'category' => 'house', 'is_house' => true,
                    'notes' => 'Platform-owned advertiser for house/upgrade campaigns.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->line('  house advertiser — created');
            } else {
                $this->line('  house advertiser — exists');
            }

            $campaignId = DB::table('ad_campaigns')
                ->where('advertiser_id', $houseId)->where('kind', 'house')->value('id');

            if (! $campaignId) {
                $campaignId = DB::table('ad_campaigns')->insertGetId([
                    'advertiser_id' => $houseId,
                    'name' => 'House — Upgrade to remove ads',
                    'kind' => 'house', 'status' => 'active',
                    'pricing_model' => 'flat', 'rate_micros' => 0,
                    'budget_total_micros' => null,   // never capped → 100% fill
                    'budget_daily_micros' => null,
                    'pacing' => 'asap', 'priority_tier' => 5,
                    'starts_at' => null, 'ends_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->line('  house campaign — created (tier 5, uncapped)');
            } else {
                $this->line('  house campaign — exists');
            }

            $slotId = $slotIds['footer_sticky'] ?? DB::table('ad_slots')->where('code', 'footer_sticky')->value('id');

            if ($slotId && ! DB::table('ad_creatives')->where('campaign_id', $campaignId)->where('slot_id', $slotId)->exists()) {
                DB::table('ad_creatives')->insert([
                    'campaign_id' => $campaignId, 'slot_id' => $slotId,
                    'type' => 'html',
                    'html' => '<span>This site is on the Free plan.</span> <strong>Upgrade to remove ads</strong>',
                    'click_url' => 'https://levelupgrowth.io/pricing',
                    'alt_text' => 'Upgrade to remove ads',
                    'weight' => 100,
                    // Ships PENDING. Nothing serves unapproved — not even ours.
                    'review_state' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->line('  house creative — created (review_state=pending)');
            } else {
                $this->line('  house creative — exists');
            }

            if ($this->option('approve-house')) {
                $n = DB::table('ad_creatives')
                    ->where('campaign_id', $campaignId)
                    ->where('review_state', 'pending')
                    ->update([
                        'review_state' => 'approved',
                        'review_note'  => 'Approved via ads:seed-platform --approve-house',
                        'reviewed_at'  => now(),
                        'updated_at'   => now(),
                    ]);
                $this->warn("  house creative — APPROVED ({$n} row(s))");
            }
        }

        // ── Settings ────────────────────────────────────────────────────
        if (! $dryRun) {
            foreach (AdSettings::definitions() as $key => [$default, $description]) {
                if (! DB::table('ad_settings')->where('key', $key)->exists()) {
                    DB::table('ad_settings')->insert([
                        'key' => $key,
                        'value' => json_encode($default, JSON_UNESCAPED_SLASHES),
                        'description' => $description,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $settings->flush();
            $this->line('  settings — materialised from code defaults');
        }

        $this->line('');
        $master = $settings->bool(AdSettings::MASTER_ENABLED);
        if ($master) {
            $this->warn('  ads_master_enabled = TRUE — advertisements CAN render.');
        } else {
            $this->info('  ads_master_enabled = FALSE — no advertisement can render anywhere.');
        }
        $this->line('');

        if ($dryRun) {
            $this->warn('Nothing written (--dry-run).');
        }

        return self::SUCCESS;
    }
}

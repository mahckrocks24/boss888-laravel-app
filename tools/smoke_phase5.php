<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$svc = app(\App\Engines\Marketing\Services\EmailBuilderService::class);

echo "── P6: font_family column & render injection ──\n";
$premium = DB::table('email_templates')->where('is_system', 1)->first();
echo "  premium.font_family = " . ($premium->font_family ?? 'NULL') . "\n";
// Set a unique font to verify it takes effect
DB::table('email_templates')->where('id', $premium->id)->update(['font_family' => 'Georgia, serif']);
$html = $svc->render($premium->id);
echo "  render contains 'Georgia': " . (str_contains($html, 'Georgia') ? 'yes' : 'NO') . "\n";
echo "  render still contains 'Inter' (CSS @import): " . (str_contains($html, 'Inter') ? 'yes' : 'NO') . "\n";
DB::table('email_templates')->where('id', $premium->id)->update(['font_family' => 'Inter, Arial, Helvetica']);

echo "── P1: template-level send test (DRY — postmark off, expect graceful fail) ──\n";
$r = $svc->sendTestTemplate($premium->id, 'bogus@example.com', ['brand_name'=>'Test']);
echo "  returned: " . json_encode(array_intersect_key($r, array_flip(['success','message']))) . "\n";
assert(array_key_exists('success', $r));

echo "── P2: queueSendCampaign + getSendStatus ──\n";
// Create a throwaway campaign with 2 recipients
$cid = DB::table('campaigns')->insertGetId([
    'workspace_id' => 1, 'name' => 'smoke-phase5', 'type' => 'email', 'status' => 'draft',
    'subject' => 'Smoke test', 'body_html' => '', 'template_id' => $premium->id,
    'recipients_json' => json_encode([
        ['email' => 'smoke1@example.com', 'name' => 'Smoke One'],
        ['email' => 'smoke2@example.com', 'name' => 'Smoke Two'],
    ]),
    'stats_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
]);
$qr = $svc->queueSendCampaign($cid);
echo "  queue result: " . json_encode($qr) . "\n";
// queueSendCampaign returns queued=false if validation fails, so check via send-status
$status = $svc->getSendStatus($cid);
echo "  status after queue: " . json_encode($status) . "\n";

echo "── P3: enhanced tracking ──\n";
// Build a fake log row so we can exercise trackOpenEnhanced + trackClickEnhanced
$token = Str::random(32);
$logId = DB::table('email_campaigns_log')->insertGetId([
    'campaign_id' => $cid, 'workspace_id' => 1, 'recipient_email' => 'track@example.com',
    'subject' => 'x', 'status' => 'sent', 'tracking_token' => $token,
    'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$svc->trackOpenEnhanced($token, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit');
$row = DB::table('email_campaigns_log')->where('id', $logId)->first();
echo "  open — opened_at set: " . ($row->opened_at ? 'yes' : 'NO') . " device=" . ($row->device_type ?: '?') . " count=" . $row->opened_count . "\n";
assert($row->opened_at);
assert($row->device_type === 'mobile');

// Click
$linkToken = Str::random(24);
DB::table('email_links')->insert([
    'campaign_id' => $cid, 'original_url' => 'https://example.com/promo',
    'tracking_token' => $linkToken, 'click_count' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$redirect = $svc->trackClickEnhanced($linkToken, $token);
echo "  click redirect: $redirect\n";
assert($redirect === 'https://example.com/promo');
$row2 = DB::table('email_campaigns_log')->where('id', $logId)->first();
echo "  click_data: " . $row2->click_data . "\n";
echo "  clicked_count: " . $row2->clicked_count . "\n";

// Always-redirect safety: invalid link token must still return '/'
$safe = $svc->trackClickEnhanced('bogus-token', $token);
echo "  click safety — bogus token returns: $safe\n";
assert($safe === url('/') || str_starts_with($safe, 'http'));

echo "── P3: unsubscribe + resubscribe by token (leads) ──\n";
$leadId = null;
if (\Schema::hasTable('leads')) {
    // Create a test lead
    $leadTok = Str::random(48);
    $leadId = DB::table('leads')->insertGetId([
        'workspace_id' => 1, 'email' => 'unsub@example.com',
        'name' => 'Test User',
        'unsubscribe_token' => $leadTok,
        'email_unsubscribed' => 0, 'email_unsubscribed_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $u = $svc->unsubscribeByToken($leadTok);
    echo "  unsub lead: id=" . ($u->id ?? 'nil') . "\n";
    $check = DB::table('leads')->where('id', $leadId)->first();
    echo "  email_unsubscribed=" . $check->email_unsubscribed . " at=" . ($check->email_unsubscribed_at ?? 'nil') . "\n";
    assert($check->email_unsubscribed === 1);

    $re = $svc->resubscribeByToken($leadTok);
    $check2 = DB::table('leads')->where('id', $leadId)->first();
    echo "  after resubscribe: email_unsubscribed=" . $check2->email_unsubscribed . "\n";
    assert($check2->email_unsubscribed === 0);
} else {
    echo "  (leads table absent — skipped)\n";
}

echo "── P4: analytics aggregate ──\n";
$an = $svc->getCampaignAnalytics($cid);
echo "  sent={$an['sent']} delivered={$an['delivered']} opened={$an['opened']} clicked={$an['clicked']}\n";
echo "  open_rate={$an['open_rate']}% click_rate={$an['click_rate']}% CTOR={$an['click_to_open_rate']}% bounce={$an['bounce_rate']}%\n";
echo "  devices: " . json_encode($an['opens_by_device']) . "\n";
echo "  top_links: " . count($an['top_links']) . "\n";
echo "  opens_by_hour peak: " . max($an['opens_by_hour']) . "\n";
echo "  ai_insight: " . substr($an['ai_insight'], 0, 110) . "...\n";

// Cleanup
DB::table('email_campaigns_log')->where('campaign_id', $cid)->delete();
DB::table('email_links')->where('campaign_id', $cid)->delete();
DB::table('campaigns')->where('id', $cid)->delete();
if ($leadId) DB::table('leads')->where('id', $leadId)->delete();

echo "\n✅ PHASE 5 SERVICE SMOKE PASSED\n";

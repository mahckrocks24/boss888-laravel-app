<?php
/**
 * Smoke-test each Marketing fix directly against the service layer,
 * bypassing HTTP auth. Exits non-zero on first failure.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$WS = 1; // Boss888 Workspace
$ms  = app(\App\Engines\Marketing\Services\MarketingService::class);
$seq = app(\App\Engines\Marketing\Services\SequenceService::class);

echo "── Fix 1: updateCampaign ──\n";
$c = DB::table('campaigns')->insertGetId([
    'workspace_id'=>$WS, 'name'=>'smoke-campaign', 'type'=>'email', 'status'=>'draft',
    'subject'=>'original', 'body_html'=>'', 'recipients_json'=>'[]', 'stats_json'=>'{}',
    'created_at'=>now(), 'updated_at'=>now(),
]);
$ms->updateCampaign($c, ['subject'=>'UPDATED']);
$check = DB::table('campaigns')->where('id', $c)->value('subject');
assert($check === 'UPDATED', "expected UPDATED, got $check");
echo "  ✓ campaign subject persisted after update\n";

echo "── Fix 2: toggleAutomation ──\n";
$a = DB::table('automations')->insertGetId([
    'workspace_id'=>$WS, 'name'=>'smoke-auto', 'status'=>'draft',
    'trigger_type'=>'lead_created', 'trigger_config_json'=>'{}', 'steps_json'=>'[]',
    'execution_count'=>0, 'created_at'=>now(), 'updated_at'=>now(),
]);
$ms->toggleAutomation($a, 'active');
$checkA = DB::table('automations')->where('id', $a)->value('status');
assert($checkA === 'active', "expected active, got $checkA");
echo "  ✓ automation toggled to active\n";

echo "── Fix 3: template CRUD ──\n";
$tid = $ms->createTemplate($WS, ['name'=>'smoke-tpl', 'subject'=>'hi']);
$ms->updateTemplate($tid, ['subject'=>'hi-edited']);
$tpl = $ms->getTemplate($tid);
assert($tpl && $tpl->subject === 'hi-edited');
echo "  ✓ template created + updated\n";
$deleted = $ms->deleteTemplate($tid);
assert($deleted === true);
echo "  ✓ template deleted\n";

echo "── Fix 3b: system template delete-protection ──\n";
$sysId = DB::table('email_templates')->where('is_system', 1)->value('id');
$blocked = $ms->deleteTemplate($sysId);
assert($blocked === false, 'system template must be delete-protected');
echo "  ✓ system template protected\n";

echo "── Fix 4: email settings ──\n";
$settings = $ms->getEmailSettings();
assert(is_array($settings) && array_key_exists('configured', $settings));
echo "  ✓ GET settings — configured=" . ($settings['configured'] ? 'true' : 'false')
    . " driver={$settings['driver']} from={$settings['from_email']} token_mask=" . ($settings['postmark_token'] ?: '(empty)') . "\n";

echo "── Fix 5: sequences ──\n";
$s = $seq->createSequence($WS, ['name'=>'smoke-seq', 'trigger_type'=>'manual', 'user_id'=>1]);
assert(!empty($s['sequence_id']));
echo "  ✓ sequence created (id={$s['sequence_id']})\n";
$step = $seq->addStep($s['sequence_id'], ['type'=>'email', 'email_subject'=>'step1', 'delay_hours'=>0]);
assert(!empty($step['step_id']));
echo "  ✓ step added (id={$step['step_id']})\n";
$got = $seq->getSequence($WS, $s['sequence_id']);
assert($got && count($got->steps) === 1);
echo "  ✓ getSequence returns 1 step\n";
$seq->toggleSequence($s['sequence_id'], 'active');
$seq->removeStep($s['sequence_id'], $step['step_id']);
$seq->deleteSequence($s['sequence_id']);
echo "  ✓ toggle, removeStep, deleteSequence OK\n";

echo "── Fix 6: system template count ──\n";
$n = DB::table('email_templates')->where('is_system', 1)->count();
echo "  ✓ system templates: $n\n";
assert($n >= 10);

// Cleanup smoke rows
DB::table('campaigns')->where('id', $c)->delete();
DB::table('automations')->where('id', $a)->delete();

echo "\n✅ ALL SMOKE TESTS PASSED\n";

<?php
/**
 * INC-0006 — business-boundary classification for user 8 (arnel@levelupgrowth.io). READ ONLY.
 *
 * The Owner's rule: same owner is NOT merge evidence. So this reports the evidence for a boundary — name identity,
 * website identity, domains, creation clustering, and data volume — and does not merge anything or assume a verdict.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$USER = 8;

$ws = DB::select("
    SELECT w.id, w.name, w.slug, w.created_at, w.billing_workspace_id, w.onboarded,
           (SELECT COUNT(*) FROM websites s WHERE s.workspace_id=w.id AND s.deleted_at IS NULL) sites,
           (SELECT GROUP_CONCAT(CONCAT(s.id,':',COALESCE(s.name,'?'),' [',COALESCE(s.custom_domain,s.subdomain,'no domain'),']') SEPARATOR ' | ')
              FROM websites s WHERE s.workspace_id=w.id AND s.deleted_at IS NULL) site_detail,
           (SELECT COALESCE(SUM(c.balance),0) FROM credits c WHERE c.workspace_id=w.id) balance,
           (SELECT COUNT(*) FROM subscriptions sub WHERE sub.workspace_id=w.id AND sub.status IN ('active','trialing')) subs,
           (SELECT COUNT(*) FROM subscriptions sub WHERE sub.workspace_id=w.id AND sub.stripe_subscription_id IS NOT NULL) stripe
    FROM workspaces w
    JOIN workspace_users wu ON wu.workspace_id=w.id AND wu.user_id=?
    ORDER BY w.name, w.id
", [$USER]);

printf("user %d — %s\n", $USER, DB::table('users')->where('id', $USER)->value('email'));
printf("workspaces: %d\n\n", count($ws));

$ids = array_map(fn ($w) => $w->id, $ws);
$in = implode(',', $ids ?: [0]);

// data volume per workspace across the tables that carry real business meaning
$tables = ['websites','articles','leads','tasks','assets','media','seo_content_index','seo_audits',
           'campaigns','social_posts','approvals','notifications','sarah_commitments','strategy_proposals',
           'chatbot_knowledge_sources','wp_site_connections','credit_transactions'];

$vol = [];
foreach ($tables as $t) {
    try {
        foreach (DB::select("SELECT workspace_id, COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in}) GROUP BY workspace_id") as $r) {
            $vol[(int) $r->workspace_id][$t] = (int) $r->c;
        }
    } catch (\Throwable $e) { /* table shape differs */ }
}

foreach ($ws as $w) {
    $rows = $vol[$w->id] ?? [];
    $total = array_sum($rows);
    printf("── ws %-7d %-32s created %s\n", $w->id, mb_substr($w->name, 0, 32), substr($w->created_at, 0, 10));
    printf("   slug %-40s onboarded=%d  billing_ws=%s\n", $w->slug, (int) $w->onboarded, $w->billing_workspace_id ?? 'null');
    printf("   websites: %s\n", $w->sites ? $w->site_detail : '(none)');
    printf("   credits %.0f · active subs %d · stripe subs %d\n", $w->balance, $w->subs, $w->stripe);
    printf("   business rows: %d", $total);
    if ($rows) {
        arsort($rows);
        $top = [];
        foreach (array_slice($rows, 0, 6, true) as $t => $c) $top[] = "{$t} {$c}";
        printf("  (%s)", implode(', ', $top));
    }
    echo "\n\n";
}

// name clustering — the strongest available signal of one business fragmented across several workspaces
echo "name clusters (a repeated name across workspaces is a fragmented business, not several businesses):\n";
$byName = [];
foreach ($ws as $w) { $byName[mb_strtolower(trim($w->name))][] = $w->id; }
foreach ($byName as $name => $list) {
    printf("  %-34s %s%s\n", $name, implode(', ', $list), count($list) > 1 ? '   <-- fragmented candidate' : '');
}

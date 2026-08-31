<?php
/**
 * INC-0006 §13 — platform-wide blast radius. READ ONLY.
 *
 * Detects workspaces that were probably created BY the defect rather than by a person, without assuming the answer.
 * The provisioner left a recognisable fingerprint: slug = Str::slug(name) . '-' . 6 hex chars, onboarded = 1 at
 * creation, and a workspace whose single website carries the same name.
 *
 * Each signal is reported separately so the Owner can see the evidence rather than a verdict.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$DEFECT_FROM = '2026-06-24';   // the date the "website = its own workspace" comment was written

$rows = DB::select("
    SELECT w.id, w.name, w.slug, w.created_by, w.created_at, w.billing_workspace_id, w.onboarded,
           (SELECT COUNT(*) FROM websites s WHERE s.workspace_id = w.id AND s.deleted_at IS NULL) sites,
           (SELECT s.name FROM websites s WHERE s.workspace_id = w.id AND s.deleted_at IS NULL ORDER BY s.id LIMIT 1) site_name,
           (SELECT COUNT(*) FROM subscriptions sub WHERE sub.workspace_id = w.id AND sub.status IN ('active','trialing')) subs,
           (SELECT COUNT(*) FROM subscriptions sub WHERE sub.workspace_id = w.id AND sub.stripe_subscription_id IS NOT NULL) stripe_subs,
           (SELECT COALESCE(SUM(c.balance),0) FROM credits c WHERE c.workspace_id = w.id) balance
    FROM workspaces w
    ORDER BY w.id
");

$slugFingerprint = 0; $nameMatch = 0; $suspects = [];
foreach ($rows as $r) {
    $slugLooksGenerated = (bool) preg_match('/-[0-9a-f]{6}$/', (string) $r->slug);
    $nameMatchesSite    = $r->sites == 1 && $r->site_name !== null
                          && strcasecmp(trim((string) $r->site_name), trim((string) $r->name)) === 0;
    $afterDefect        = $r->created_at >= $DEFECT_FROM;
    if ($slugLooksGenerated) $slugFingerprint++;
    if ($nameMatchesSite) $nameMatch++;
    if ($afterDefect && ($slugLooksGenerated || $nameMatchesSite)) {
        $suspects[] = $r;
    }
}

$owners = [];
foreach ($suspects as $s) { $owners[$s->created_by] = true; }

printf("workspaces on the platform            : %d\n", count($rows));
printf("slug matches the provisioner pattern  : %d\n", $slugFingerprint);
printf("workspace name == its single website  : %d\n", $nameMatch);
printf("SUSPECTED accidental website-workspaces (created >= %s): %d\n", $DEFECT_FROM, count($suspects));
printf("distinct owners affected              : %d\n\n", count($owners));

$sites = 0; $subs = 0; $stripe = 0; $bal = 0.0;
printf("%-8s %-34s %-6s %-5s %-6s %-9s %-10s %s\n", 'WS', 'NAME', 'SITES', 'SUBS', 'STRIPE', 'BALANCE', 'OWNER', 'CREATED');
echo str_repeat('-', 110), "\n";
foreach ($suspects as $s) {
    $sites += (int) $s->sites; $subs += (int) $s->subs; $stripe += (int) $s->stripe_subs; $bal += (float) $s->balance;
    printf("%-8d %-34s %-6d %-5d %-6d %-9.0f %-10d %s\n",
        $s->id, mb_substr((string) $s->name, 0, 34), $s->sites, $s->subs, $s->stripe_subs, $s->balance,
        $s->created_by, substr((string) $s->created_at, 0, 10));
}

printf("\nwebsites inside suspected workspaces  : %d\n", $sites);
printf("active subscriptions                  : %d\n", $subs);
printf("with a real Stripe subscription id    : %d\n", $stripe);
printf("credits held in suspected workspaces  : %.0f\n", $bal);

if ($suspects) {
    $first = min(array_map(fn ($s) => $s->created_at, $suspects));
    $last  = max(array_map(fn ($s) => $s->created_at, $suspects));
    printf("earliest / latest creation            : %s / %s\n", substr($first, 0, 16), substr($last, 0, 16));
}

// Did anyone ACTIVELY use more than one fragmented workspace? Chat is gone, so use tasks + articles as activity.
echo "\nowners with activity in more than one suspected workspace:\n";
$byOwner = [];
foreach ($suspects as $s) { $byOwner[$s->created_by][] = $s->id; }
$anyMulti = false;
foreach ($byOwner as $owner => $wsIds) {
    if (count($wsIds) < 2) continue;
    $active = 0;
    foreach ($wsIds as $wsId) {
        $n = (int) DB::selectOne("SELECT (SELECT COUNT(*) FROM tasks WHERE workspace_id = ?) +
                                         (SELECT COUNT(*) FROM articles WHERE workspace_id = ?) c",
                                 [$wsId, $wsId])->c;
        if ($n > 0) $active++;
    }
    if ($active >= 2) {
        $anyMulti = true;
        printf("  user %d — %d suspected workspaces, %d of them with real activity\n", $owner, count($wsIds), $active);
    }
}
if (! $anyMulti) echo "  none\n";

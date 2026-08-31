<?php
/**
 * ARCH-2 (prepared 2026-08-31) — consolidate an owner's websites into ONE workspace.
 *
 * The Owner's architecture: one workspace per owner, every website listed on the Websites page, nothing switching
 * except the SEO Engine. ARCH-1 stopped NEW websites being given their own workspace; this moves the ones already
 * scattered.
 *
 * NOT RUN AUTOMATICALLY. It moves real customer rows and changes which credit pool and plan quota a site falls
 * under, which is the Owner's call, so it defaults to a DRY RUN and prints exactly what it would do.
 *
 *   php consolidate.php            # dry run — prints the plan, changes nothing
 *   php consolidate.php --apply    # performs the move inside a transaction
 *
 * Safety:
 *   - refuses to run if the destination plan cannot hold the resulting site count
 *   - moves websites and their pages together, in one transaction
 *   - leaves the emptied workspaces in place (never deletes a workspace)
 *   - prints a before/after count that can be checked independently
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$OWNER_USER_ID = 2;      // red@levelupgrowth.io
$DEST_WS       = 2;      // Chef Red Raymundo — the owner's main workspace
$apply         = in_array('--apply', $argv, true);

$destName = DB::table('workspaces')->where('id', $DEST_WS)->value('name');
if (! $destName) { fwrite(STDERR, "destination workspace {$DEST_WS} not found\n"); exit(1); }

// Every workspace this user owns, except the destination.
$sourceWs = DB::table('workspace_users')
    ->where('user_id', $OWNER_USER_ID)
    ->where('workspace_id', '!=', $DEST_WS)
    ->pluck('workspace_id')->all();

$sites = DB::table('websites')
    ->whereIn('workspace_id', $sourceWs)
    ->whereNull('deleted_at')
    ->get(['id', 'workspace_id', 'name', 'status']);

$before = (int) DB::table('websites')->where('workspace_id', $DEST_WS)->whereNull('deleted_at')->count();

// Plan headroom on the destination — never move a customer into a limit breach.
$sub  = DB::table('subscriptions')->where('workspace_id', $DEST_WS)->whereIn('status', ['active', 'trialing'])->latest()->first();
$plan = $sub ? DB::table('plans')->where('id', $sub->plan_id)->first() : DB::table('plans')->where('slug', 'free')->first();
$max  = (int) ($plan->max_websites ?? 1);
$after = $before + $sites->count();

printf("destination      : %d (%s)\n", $DEST_WS, $destName);
printf("plan             : %s, max_websites %d\n", $plan->name ?? '?', $max);
printf("sites now / after: %d / %d\n\n", $before, $after);

foreach ($sites as $s) {
    $pages = (int) DB::table('pages')->where('website_id', $s->id)->count();
    printf("  move site %-4d %-28s %-10s from ws %-7d (%d pages)\n", $s->id, mb_substr($s->name ?? '', 0, 28), $s->status, $s->workspace_id, $pages);
}

if ($sites->isEmpty()) { echo "\nnothing to move.\n"; exit(0); }

if ($after > $max) {
    printf("\nREFUSING: %d sites would exceed the plan limit of %d. Raise the plan or exclude sites first.\n", $after, $max);
    exit(2);
}

if (! $apply) {
    echo "\nDRY RUN — nothing changed. Re-run with --apply to perform the move.\n";
    exit(0);
}

DB::transaction(function () use ($sites, $DEST_WS) {
    foreach ($sites as $s) {
        DB::table('websites')->where('id', $s->id)->update(['workspace_id' => $DEST_WS, 'updated_at' => now()]);
    }
});

$now = (int) DB::table('websites')->where('workspace_id', $DEST_WS)->whereNull('deleted_at')->count();
printf("\nmoved %d site(s). Destination now holds %d website(s).\n", $sites->count(), $now);
printf("Emptied workspaces were left in place and can be removed separately.\n");

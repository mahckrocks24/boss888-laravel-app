<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * business:reconcile — enforce the Owner's rule (2026-09-22): 1 PUBLISHED website = 1 profile; a draft has none.
 * Per workspace: every published website gets its own profile (1:1); draft websites are detached (business_id null);
 * profiles that end up owning no published website are removed; exactly one profile is the default (the oldest
 * published website's — it mirrors workspaces.*). Idempotent. A safety net for publishes done via Sarah/Arthur/exec
 * (the UI publish route hooks ensureForWebsite directly). Run: php artisan business:reconcile [--workspace=ID] [--dry-run]
 */
class BusinessReconcileCommand extends Command
{
    protected $signature = 'business:reconcile {--workspace= : one workspace id} {--dry-run}';
    protected $description = '1 published website = 1 profile; drafts have none (RFC-0011 Owner rule)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('workspaces')->orderBy('id');
        if ($this->option('workspace')) { $q->where('id', (int) $this->option('workspace')); }
        $created = 0; $detached = 0; $removed = 0;

        foreach ($q->pluck('id') as $wsId) {
            $wsId = (int) $wsId;
            // 1) detach every draft (and deleted) website from any profile
            $draftIds = DB::table('websites')->where('workspace_id', $wsId)->where(function ($w) { $w->where('status', '!=', 'published')->orWhereNotNull('deleted_at'); })->whereNotNull('business_id')->pluck('id');
            if ($draftIds->count()) { if (! $dry) { DB::table('websites')->whereIn('id', $draftIds)->update(['business_id' => null]); } $detached += $draftIds->count(); }

            // 2) every published website has its own profile (1:1). Published websites in creation order; the oldest is default.
            $pub = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->orderBy('published_at')->orderBy('id')->get(['id', 'name', 'template_industry', 'business_id']);
            $seenBiz = [];
            foreach ($pub as $w) {
                $bid = (int) ($w->business_id ?? 0);
                // a profile may be shared (old backfill default) — only the FIRST published website keeps it; others get their own
                if ($bid > 0 && ! isset($seenBiz[$bid]) && Business::where('workspace_id', $wsId)->where('id', $bid)->exists()) {
                    $seenBiz[$bid] = (int) $w->id;
                    continue;
                }
                // needs its own profile
                if ($dry) { $this->line("ws {$wsId}: website {$w->id} ({$w->name}) needs a profile"); $created++; continue; }
                DB::table('websites')->where('id', $w->id)->update(['business_id' => null]);   // clear a shared link before ensure creates a fresh one
                $newId = Business::ensureForWebsite($wsId, (int) $w->id);
                if ($newId) { $seenBiz[$newId] = (int) $w->id; $created++; }
            }

            // 3) remove profiles that now own no published website
            $ownedBiz = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->whereNotNull('business_id')->pluck('business_id')->map(fn ($v) => (int) $v)->unique()->all();
            $orphans = Business::where('workspace_id', $wsId)->when($ownedBiz, fn ($x) => $x->whereNotIn('id', $ownedBiz))->get();
            foreach ($orphans as $o) {
                if ($dry) { $this->line("ws {$wsId}: profile {$o->id} ({$o->name}) owns no published website → remove"); $removed++; continue; }
                $o->delete(); $removed++;
            }

            // 4) exactly one default = the oldest published website's profile
            if (! $dry) {
                $firstPub = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->whereNotNull('business_id')->orderBy('published_at')->orderBy('id')->value('business_id');
                if ($firstPub) {
                    Business::where('workspace_id', $wsId)->where('id', '!=', (int) $firstPub)->where('is_default', true)->update(['is_default' => false]);
                    $def = Business::where('workspace_id', $wsId)->where('id', (int) $firstPub)->first();
                    if ($def && ! $def->is_default) { $def->is_default = true; $def->save(); }
                }
            }
        }
        $this->info(($dry ? '[dry-run] ' : '') . "profiles created: {$created}, draft websites detached: {$detached}, profiles removed: {$removed}");
        return 0;
    }
}

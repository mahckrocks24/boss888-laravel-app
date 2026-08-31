<?php

namespace App\Console\Commands;

use App\Core\Tenancy\WebsiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INC-0006 — retire the workspaces the builder created for websites, when the build never produced one.
 *
 * Between 2026-06-24 and the ARCH-1 gate, provisioning a website also provisioned a workspace. Where the build
 * then failed or was abandoned, what is left is a workspace with no website: not a business, not a customer,
 * and not something a customer surface should ever list.
 *
 * What this command does is deliberately small. It sets `lifecycle_state` and records why. It does not delete
 * a row, move a row between workspaces, cancel a subscription, or touch a credit ledger — every one of those
 * would rewrite history the Owner has ruled must stay intact, and none of them is necessary to stop a dead
 * workspace appearing in the product.
 *
 * It refuses to touch anything a live business still depends on. "Live" means a subscription with a payment
 * provider actually behind it, a credit balance, a website, another workspace billing into it, or a second
 * member. A subscription row with no provider id is not evidence of a customer: the same provisioner that
 * created these workspaces minted one for each of them, with no Stripe subscription behind it.
 *
 * Dry run is the default. Nothing is written without --apply.
 */
class ArchiveFailedBuildWorkspacesCommand extends Command
{
    protected $signature = 'inc0006:archive-failed-builds
                            {--apply : actually write lifecycle_state (default is a dry run)}
                            {--since=2026-06-24 : only consider workspaces created on or after this date}';

    protected $description = 'INC-0006: mark website-less provisioner workspaces as archived failed builds (dry run by default)';

    /**
     * Workspaces that must never be archived by this command whatever the evidence says. QA fixtures the
     * Owner has designated are marked `qa` instead, so they stay reachable through admin and QA paths.
     */
    private const QA_FIXTURES = [999993, 999994, 999995, 999997, 999909];

    /**
     * Reserved domains that identify an account as a test account rather than a customer. Both are set aside
     * by RFC for exactly this purpose and can never belong to a real business, so a workspace created by such
     * an account is QA evidence — preserved and reachable through admin, never archived as a failed build and
     * never shown as somebody's business.
     */
    private const TEST_EMAIL_DOMAINS = ['.test', '@example.com', '@example.org', '.invalid', '.localhost'];

    /** Fixture workspaces recognisable by the slug their harness generates. */
    private const FIXTURE_SLUG_PREFIXES = ['exp888-'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $since = (string) $this->option('since');

        $candidates = DB::table('workspaces')
            ->where('lifecycle_state', 'active')
            // Provisioner-created workspaces, plus any fixture the Owner named outright - a
            // designated fixture must be marked whether or not its slug matches the pattern.
            ->where(function ($q) use ($since) {
                $q->where(function ($p) use ($since) {
                    $p->where('created_at', '>=', $since)
                      ->where('slug', 'REGEXP', '-[0-9a-f]{6}$');
                })
                ->orWhereIn('id', self::QA_FIXTURES);
            })
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'created_by', 'created_at', 'billing_workspace_id']);

        $archive = [];
        $blocked = [];
        $qa      = [];

        foreach ($candidates as $ws) {
            // QA first: a fixture is never a failed build, and being a fixture outranks holding a website.
            if ($this->isQaFixture($ws)) {
                $qa[] = $ws;
                continue;
            }

            $why = $this->blockers((int) $ws->id);
            $why ? $blocked[(int) $ws->id] = $why : $archive[] = $ws;
        }

        $this->line('');
        $this->info(sprintf('INC-0006 failed-build archival — %s', $apply ? 'APPLYING' : 'DRY RUN (nothing will be written)'));
        $this->line(sprintf('  candidates carrying the provisioner slug since %s: %d', $since, $candidates->count()));
        $this->line(sprintf('  recognised as QA fixtures (marked qa, never archived): %d', count($qa)));
        $this->line(sprintf('  blocked because a live business still depends on them: %d', count($blocked)));
        $this->line(sprintf('  to archive as failed builds: %d', count($archive)));
        $this->line('');

        if ($qa) {
            $this->info('QA fixtures — preserved, marked qa, hidden from customer surfaces:');
            foreach ($qa as $w) {
                $this->line(sprintf('  ws %-8d %-34s %s', $w->id, mb_strimwidth((string) $w->name, 0, 34, '…'), $w->qa_reason));
            }
            $this->line('');
        }

        if ($blocked) {
            $this->warn('Blocked — left exactly as they are:');
            foreach ($blocked as $id => $why) {
                $this->line(sprintf('  ws %-8d %s', $id, implode('; ', $why)));
            }
            $this->line('');
        }

        if (! $archive && ! $qa) {
            $this->info('Nothing to archive and no fixtures to mark.');

            return self::SUCCESS;
        }

        $this->table(
            ['workspace', 'name', 'created', 'rows kept in place'],
            array_map(fn ($w) => [
                $w->id,
                mb_strimwidth((string) $w->name, 0, 34, '…'),
                substr((string) $w->created_at, 0, 10),
                number_format($this->rowsHeld((int) $w->id)),
            ], $archive),
        );

        if (! $apply) {
            $this->line('');
            $this->comment('Dry run. Re-run with --apply to write lifecycle_state. No row is deleted or moved either way.');

            return self::SUCCESS;
        }

        $now = now();

        foreach ($qa as $w) {
            DB::table('workspaces')->where('id', $w->id)->update([
                'lifecycle_state' => 'qa',
                'archived_at'     => $now,
                'archived_reason' => 'INC-0006: QA fixture — ' . $w->qa_reason,
                'updated_at'      => $now,
            ]);

            Log::info('[INC-0006] workspace marked as a QA fixture', [
                'workspace_id' => (int) $w->id,
                'reason'       => $w->qa_reason,
            ]);
        }
        foreach ($archive as $w) {
            DB::table('workspaces')->where('id', $w->id)->update([
                'lifecycle_state' => 'archived_failed_build',
                'archived_at'     => $now,
                'archived_reason' => 'INC-0006: created by website provisioning; the build produced no website',
                'updated_at'      => $now,
            ]);

            Log::info('[INC-0006] workspace archived as a failed build', [
                'workspace_id' => (int) $w->id,
                'name'         => $w->name,
                'created_by'   => (int) $w->created_by,
            ]);
        }

        $this->line('');
        $this->info(sprintf(
            'Archived %d failed build(s) and marked %d QA fixture(s). No rows were deleted, moved, or re-parented.',
            count($archive),
            count($qa),
        ));

        return self::SUCCESS;
    }

    /**
     * Is this workspace a deliberate test fixture rather than somebody's business? Decided from evidence -
     * the reserved email domain of the account that created it, or the slug its harness generates - never
     * from the workspace looking unused. Sets `qa_reason` on the row for the report.
     */
    private function isQaFixture(object $ws): bool
    {
        if (in_array((int) $ws->id, self::QA_FIXTURES, true)) {
            $ws->qa_reason = 'designated by the Owner';

            return true;
        }

        foreach (self::FIXTURE_SLUG_PREFIXES as $prefix) {
            if (str_starts_with((string) $ws->slug, $prefix)) {
                $ws->qa_reason = "fixture slug '{$prefix}*'";

                return true;
            }
        }

        $email = (string) DB::table('users')->where('id', $ws->created_by)->value('email');
        foreach (self::TEST_EMAIL_DOMAINS as $domain) {
            if ($email !== '' && str_ends_with(strtolower($email), $domain)) {
                $ws->qa_reason = "created by a reserved test domain ({$domain})";

                return true;
            }
        }

        return false;
    }

    /**
     * Reasons a workspace must be left alone. Empty means nothing real depends on it.
     *
     * @return list<string>
     */
    private function blockers(int $wsId): array
    {
        $why = [];

        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->count();
        if ($sites > 0) {
            $why[] = "holds {$sites} website(s)";
        }

        // Only a subscription with a payment provider behind it is evidence of a paying customer.
        $paid = DB::table('subscriptions')
            ->where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNotNull('stripe_subscription_id')->orWhereNotNull('provider_subscription_id');
            })
            ->count();
        if ($paid > 0) {
            $why[] = 'has a subscription with a payment provider behind it';
        }

        $balance = (float) DB::table('credits')->where('workspace_id', $wsId)->sum('balance');
        if ($balance > 0) {
            $why[] = sprintf('holds %.0f credits', $balance);
        }

        $poolers = DB::table('workspaces')
            ->where('billing_workspace_id', $wsId)
            ->where('id', '!=', $wsId)
            ->count();
        if ($poolers > 0) {
            $why[] = "{$poolers} other workspace(s) bill into it";
        }

        $members = DB::table('workspace_users')->where('workspace_id', $wsId)->distinct()->count('user_id');
        if ($members > 1) {
            $why[] = "{$members} members";
        }

        return $why;
    }

    /** How much history stays exactly where it is. Reported so archival is never mistaken for deletion. */
    private function rowsHeld(int $wsId): int
    {
        $total = 0;

        foreach (['tasks', 'articles', 'notifications', 'strategy_proposals', 'credit_transactions'] as $t) {
            try {
                $total += (int) DB::table($t)->where('workspace_id', $wsId)->count();
            } catch (\Throwable) {
                // table shape differs on some environments; a missing table simply contributes nothing
            }
        }

        return $total;
    }
}

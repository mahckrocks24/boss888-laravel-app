<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INC-0006 — retire the entitlement that came with a workspace nobody asked for.
 *
 * When the builder created a workspace for every website, it also minted a subscription row for each one. Those
 * workspaces produced no website and are archived; the subscriptions remain, `active`, granting plan features to
 * something that is not a business.
 *
 * What this does and does not do matters:
 *
 *   - it does NOT delete a subscription row. The history of what was created stays readable.
 *   - it does NOT contact Stripe, and it refuses outright to touch any row that carries a payment provider
 *     reference. Where no provider relationship exists there is nothing to cancel upstream, and where one does
 *     exist this command is the wrong instrument.
 *   - it only ever looks at workspaces already proven to be archived failed builds. An ambiguous workspace, or
 *     one still holding a website, is never in scope — that decision was made and evidenced separately.
 *
 * The transition is `status = cancelled` with a timestamp and a written reason, so entitlement stops at the
 * same gates every other cancellation stops at, and the record explains itself without reference to a log.
 *
 * Dry run is the default.
 */
class RetireFailedBuildEntitlementsCommand extends Command
{
    protected $signature = 'inc0006:retire-failed-build-entitlements
                            {--apply : actually write the cancellation (default is a dry run)}';

    protected $description = 'INC-0006: cancel provider-less entitlement on archived failed-build workspaces (dry run by default)';

    private const REASON = 'INC-0006: workspace archived as a failed build; entitlement was provisioner-minted, never purchased';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $archived = DB::table('workspaces')
            ->where('lifecycle_state', Workspace::STATE_ARCHIVED)
            ->pluck('name', 'id');

        if ($archived->isEmpty()) {
            $this->info('No archived failed-build workspaces. Nothing to do.');

            return self::SUCCESS;
        }

        $rows = DB::table('subscriptions')
            ->whereIn('workspace_id', $archived->keys())
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->orderBy('workspace_id')
            ->get(['id', 'workspace_id', 'status', 'provider', 'stripe_subscription_id', 'provider_subscription_id']);

        $retire  = [];
        $refused = [];

        foreach ($rows as $r) {
            // A payment provider behind the row means a real commercial relationship. Out of scope, always.
            ($r->stripe_subscription_id !== null || $r->provider_subscription_id !== null)
                ? $refused[] = $r
                : $retire[] = $r;
        }

        $this->line('');
        $this->info(sprintf('INC-0006 entitlement retirement — %s', $apply ? 'APPLYING' : 'DRY RUN (nothing will be written)'));
        $this->line(sprintf('  archived failed-build workspaces: %d', $archived->count()));
        $this->line(sprintf('  live entitlement rows inside them: %d', $rows->count()));
        $this->line(sprintf('  refused because a payment provider is attached: %d', count($refused)));
        $this->line(sprintf('  to cancel: %d', count($retire)));
        $this->line('');

        foreach ($refused as $r) {
            $this->warn(sprintf('  ws %-8d subscription %d carries a provider reference — LEFT UNTOUCHED', $r->workspace_id, $r->id));
        }

        if (! $retire) {
            $this->info('Nothing to cancel.');

            return self::SUCCESS;
        }

        $this->table(
            ['workspace', 'name', 'subscription', 'status now', 'provider'],
            array_map(fn ($r) => [
                $r->workspace_id,
                mb_strimwidth((string) $archived[$r->workspace_id], 0, 30, '…'),
                $r->id,
                $r->status,
                $r->provider . ' (no reference)',
            ], $retire),
        );

        if (! $apply) {
            $this->line('');
            $this->comment('Dry run. Re-run with --apply. No row is deleted either way, and Stripe is never contacted.');

            return self::SUCCESS;
        }

        $now = now();
        foreach ($retire as $r) {
            DB::table('subscriptions')->where('id', $r->id)->update([
                'status'           => 'cancelled',
                'cancelled_at'     => $now,
                'cancelled_reason' => self::REASON,
                'updated_at'       => $now,
            ]);

            Log::info('[INC-0006] provisioner-minted entitlement retired', [
                'workspace_id'    => (int) $r->workspace_id,
                'subscription_id' => (int) $r->id,
                'was'             => $r->status,
                'reason'          => self::REASON,
            ]);
        }

        $this->line('');
        $this->info(sprintf('Cancelled %d entitlement row(s). None deleted; Stripe untouched.', count($retire)));

        return self::SUCCESS;
    }
}

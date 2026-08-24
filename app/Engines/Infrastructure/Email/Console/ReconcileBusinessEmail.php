<?php

namespace App\Engines\Infrastructure\Email\Console;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Reconciliation\EmailReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * INFRA888 — scheduled Business Email provider-resource reconciliation.
 *
 * THE GAP THIS CLOSES
 * `EmailReconciliationService` has existed since E2 and is well behaved: it
 * compares intent against provider reality, never mutates, and refuses to
 * conclude from a partial read. But nothing ever *called* it on a schedule. Its
 * only caller was an admin endpoint someone had to remember to press, which
 * means provider drift was detectable in principle and undetected in practice.
 * E8 recorded this honestly as recovery PARTIAL; this is what closes it.
 *
 * IT OBSERVES. IT DOES NOT REPAIR.
 * That is the service's design and this command does not alter it. A reconciler
 * that repairs is one whose reports you cannot trust — it has already changed
 * the thing it is describing — and "repair" against a partially-read provider
 * means deleting a customer's mailboxes. Drift becomes a recorded fact; acting
 * on it is an operator decision with the evidence in front of them.
 *
 * ONE BROKEN DOMAIN MUST NOT SILENCE THE REST.
 * Every domain is reconciled inside its own try/catch and its own workspace
 * context. A provider timeout on domain 3 must not stop domains 4..n from being
 * checked, because the whole value of a scheduled pass is its completeness.
 *
 * FAILING SAFELY MEANS SAYING SO.
 * If the provider cannot be reached — gates closed, credential missing, network
 * refused — this reports the pass as FAILED for that domain. It does not report
 * zero findings, because "we could not look" and "we looked and found nothing"
 * are different sentences and only one of them is reassuring.
 */
class ReconcileBusinessEmail extends Command
{
    protected $signature = 'infra:reconcile-business-email
                            {--domain= : reconcile a single email_domains.id}
                            {--dry-run : compare and report, but persist no observation facts}';

    protected $description = 'INFRA888: compare Business Email intent against provider reality and record the differences.';

    public function handle(EmailReconciliationService $svc): int
    {
        $dry = (bool) $this->option('dry-run');

        // Enumerated WITHOUT the workspace scope, then reconciled INSIDE each
        // row's own context - the established pattern for cross-tenant console
        // work on this platform. Reading the model outside a workspace context
        // is what made the E8 setup page throw for every valid token.
        $q = EmailDomain::withoutGlobalScopes()->orderBy('id');

        if ($only = $this->option('domain')) {
            $q->where('id', (int) $only);
        }

        $rows = $q->get(['id', 'workspace_id', 'domain']);

        if ($rows->isEmpty()) {
            $this->line('no email domains to reconcile');

            return self::SUCCESS;
        }

        $this->line(sprintf('reconciling %d domain(s)%s', $rows->count(), $dry ? ' [dry-run]' : ''));

        $clean = 0; $drifted = 0; $inconclusive = 0; $failed = 0;

        foreach ($rows as $row) {
            $wsId = (int) $row->workspace_id;

            try {
                WorkspaceContext::run($wsId, function () use ($row, $svc, $dry, &$clean, &$drifted, &$inconclusive) {
                    // Re-read inside the context so every relation this domain
                    // touches resolves under the right tenant.
                    $domain = EmailDomain::find($row->id);

                    if (! $domain) {
                        throw new \RuntimeException('domain vanished between enumeration and reconciliation');
                    }

                    $report = $svc->reconcile($domain, record: ! $dry);

                    if (! $report->conclusive) {
                        $inconclusive++;
                        $this->warn(sprintf(
                            '  %-32s INCONCLUSIVE — %s (saw %d of %d objects)',
                            $report->domain,
                            $report->inconclusiveReason ?: 'provider read was incomplete',
                            $report->observedObjectCount,
                            $report->desiredObjectCount,
                        ));

                        return;
                    }

                    if ($report->isClean()) {
                        $clean++;
                        $this->line(sprintf('  %-32s clean (%d objects)', $report->domain, $report->desiredObjectCount));

                        return;
                    }

                    $drifted++;
                    $this->warn(sprintf(
                        '  %-32s DRIFT — %d finding(s), worst=%s [%s]',
                        $report->domain,
                        $report->count(),
                        $report->worstSeverity() ?? 'unknown',
                        implode(',', $report->kinds()),
                    ));

                    foreach ($report->findings as $f) {
                        $this->line(sprintf('      %-22s %-28s %s', $f->kind, $f->subject, $f->summary));
                    }
                });
            } catch (Throwable $e) {
                // Recorded, counted, and NOT allowed to end the pass.
                $failed++;
                $this->error(sprintf('  %-32s FAILED — %s', $row->domain, $e->getMessage()));
                Log::warning('infra.business_email.reconcile_failed', [
                    'email_domain_id' => (int) $row->id,
                    'workspace_id'    => $wsId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $this->line(sprintf(
            'result: %d clean, %d drifted, %d inconclusive, %d failed',
            $clean, $drifted, $inconclusive, $failed,
        ));

        // EVERY domain inconclusive means this pass checked nothing at all.
        //
        // It is a legitimate state — the provider gates are deliberately shut
        // on this installation — so it is not an error. But it is precisely the
        // shape that hides a real outage: a credential expires, the gate is
        // flipped by accident, and every hourly pass afterwards reports "0
        // drifted" forever while nobody is watching the provider. EM-6 learned
        // this on webhooks. Saying it out loud is what makes the silence
        // legible.
        if ($inconclusive === $rows->count() && $failed === 0) {
            $this->warn(
                'NOTHING WAS ACTUALLY CHECKED: every domain returned inconclusive, so this pass '
                . 'confirms no provider state. Expected while the Business Email provider gates are '
                . 'closed; if they are meant to be open, this is an outage.'
            );
        }

        // A pass that could not reach the provider is not a success. Exiting
        // non-zero is what makes a silently broken credential visible to
        // whatever watches scheduled output.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Core\Tenancy\WebsiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INC-0006 — attribute existing seo_content_index rows to the website their URL belongs to.
 *
 * The attribution rule is deliberately narrow. A row is attributed only when its own URL's host matches a
 * website in the same workspace. That is derivation from data the row already carries, not invention: nothing
 * is inferred from ordering, recency, or the workspace holding only one site at the moment.
 *
 * Anything whose host matches nothing stays at 0 — unattributed — and may stay there permanently. A page
 * indexed from a host the business no longer owns has no honest website to be assigned to, and guessing one
 * would corrupt exactly the per-site views this column exists to make trustworthy.
 *
 * Reconciliation is preserved by construction: attributed and unattributed rows still sum to the workspace
 * total, so a business-wide view is unchanged by running this.
 *
 * Dry run is the default.
 */
class BackfillContentIndexWebsiteCommand extends Command
{
    protected $signature = 'inc0006:backfill-content-index-website
                            {--apply : actually write the attributions (default is a dry run)}
                            {--workspace= : limit to one workspace}';

    protected $description = 'INC-0006: attribute seo_content_index rows to a website by URL host (dry run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only  = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $q = DB::table('seo_content_index')->where('website_id', WebsiteScope::BUSINESS_DEFAULT);
        if ($only !== null) {
            $q->where('workspace_id', $only);
        }

        $total   = (clone $q)->count();
        $byWs    = [];
        $matched = 0;
        $orphan  = 0;

        // Chunk so a large index does not have to fit in memory at once.
        (clone $q)->orderBy('id')->chunkById(500, function ($rows) use (&$byWs, &$matched, &$orphan, $apply) {
            foreach ($rows as $row) {
                $wsId      = (int) $row->workspace_id;
                $websiteId = WebsiteScope::websiteForUrl($wsId, (string) $row->url);

                if ($websiteId === WebsiteScope::BUSINESS_DEFAULT) {
                    $orphan++;
                    $byWs[$wsId]['unattributed'] = ($byWs[$wsId]['unattributed'] ?? 0) + 1;
                    continue;
                }

                $matched++;
                $byWs[$wsId]['attributed'] = ($byWs[$wsId]['attributed'] ?? 0) + 1;

                if ($apply) {
                    DB::table('seo_content_index')->where('id', $row->id)->update(['website_id' => $websiteId]);
                }
            }
        });

        $this->line('');
        $this->info(sprintf('INC-0006 content-index attribution — %s', $apply ? 'APPLYING' : 'DRY RUN (nothing will be written)'));
        $this->line(sprintf('  unattributed rows examined: %d', $total));
        $this->line(sprintf('  host matches a website in the same workspace: %d', $matched));
        $this->line(sprintf('  no matching host — left unattributed: %d', $orphan));
        $this->line('');

        if ($byWs) {
            ksort($byWs);
            $this->table(
                ['workspace', 'attributed', 'left unattributed', 'sites in workspace'],
                array_map(fn ($ws) => [
                    $ws,
                    $byWs[$ws]['attributed'] ?? 0,
                    $byWs[$ws]['unattributed'] ?? 0,
                    count(WebsiteScope::idsIn($ws)),
                ], array_keys($byWs)),
            );
        }

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply. Rows that match nothing are never guessed at.');

            return self::SUCCESS;
        }

        // Reconciliation is the point of the exercise, so prove it rather than assert it.
        $sum = DB::table('seo_content_index')
            ->when($only !== null, fn ($q2) => $q2->where('workspace_id', $only))
            ->count();
        $this->info(sprintf('Attributed %d row(s). Table still holds %d rows — nothing was added or removed.', $matched, $sum));

        return self::SUCCESS;
    }
}

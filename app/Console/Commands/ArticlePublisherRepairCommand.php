<?php

namespace App\Console\Commands;

use App\Core\Business\CanonicalSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * K1 (2026-09-25) — repair the publisher identity already stored in article JSON-LD.
 *
 * WriteService now composes the publisher from CanonicalSite, so every NEW or
 * re-enriched article is correct. This command repairs what is already stored:
 * on 2026-09-24, 195 of 202 blobs named the platform (174), staging (11),
 * 127.0.0.1 (8) or a doubled host (2) instead of the customer.
 *
 * Bounded: it rewrites ONLY the author and publisher nodes of the first @graph
 * entry. Headline, description, dates, FAQPage and everything else are copied
 * through untouched. An article whose resolved identity is unchanged is skipped.
 *
 * Reversible: every article it touches is written to a restore file under
 * storage/app/k1-repair/ BEFORE the update, and --rollback=<file> puts them back
 * exactly. Idempotent: running twice changes nothing the second time.
 *
 * Dry run is the default. --apply is required to write.
 */
class ArticlePublisherRepairCommand extends Command
{
    protected $signature = 'articles:repair-publisher
        {--apply : write the repair (default is a dry run)}
        {--workspace= : limit to one workspace id}
        {--rollback= : restore from a file written by a previous --apply}';

    protected $description = 'K1: rewrite the author/publisher identity stored in article JSON-LD from CanonicalSite';

    public function handle(CanonicalSite $canonical): int
    {
        if ($file = $this->option('rollback')) {
            return $this->rollback((string) $file);
        }

        $apply = (bool) $this->option('apply');
        $query = DB::table('articles')->whereNull('deleted_at')
            ->whereNotNull('jsonld_json')->where('jsonld_json', '<>', '');
        if ($ws = $this->option('workspace')) {
            $query->where('workspace_id', (int) $ws);
        }
        $rows = $query->get(['id', 'workspace_id', 'website_id', 'jsonld_json']);

        $changed = [];
        $skipped = 0;
        $unparseable = 0;
        $moves = [];

        foreach ($rows as $row) {
            $doc = json_decode((string) $row->jsonld_json, true);
            if (! is_array($doc) || empty($doc['@graph'][0])) {
                $unparseable++;
                continue;
            }

            $identity = $canonical->forArticle((int) $row->id, (int) $row->workspace_id);
            $name = (string) ($identity['name'] ?? '');
            $url = $identity['url'] ?? null;

            $node = ['@type' => 'Organization', 'name' => $name];
            if ($url) {
                $node['url'] = $url;
            }

            $current = $doc['@graph'][0]['publisher'] ?? null;
            if ($current === $node && ($doc['@graph'][0]['author'] ?? null) === $node) {
                $skipped++;
                continue;
            }

            $was = (string) ($current['url'] ?? '(none)');
            $now = $url ?: '(no url)';
            $moves[$was . ' -> ' . $now] = ($moves[$was . ' -> ' . $now] ?? 0) + 1;

            $doc['@graph'][0]['author'] = $node;
            $doc['@graph'][0]['publisher'] = $node;
            $changed[(int) $row->id] = [
                'old' => (string) $row->jsonld_json,
                'new' => json_encode($doc, JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->line('articles with JSON-LD : ' . count($rows));
        $this->line('  to repair           : ' . count($changed));
        $this->line('  already correct     : ' . $skipped);
        $this->line('  unparseable (left)  : ' . $unparseable);
        $this->newLine();
        foreach ($moves as $move => $count) {
            $this->line(sprintf('  %-70s %d', $move, $count));
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run with --apply to repair.');

            return self::SUCCESS;
        }

        if (! $changed) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $restore = 'k1-repair/' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($restore, json_encode(
            array_map(fn ($c) => $c['old'], $changed),
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
        $this->info('restore file: storage/app/' . $restore);

        $written = 0;
        foreach ($changed as $id => $pair) {
            DB::table('articles')->where('id', $id)->update(['jsonld_json' => $pair['new']]);
            $written++;
        }

        $this->info("repaired {$written} article(s).");
        $this->line('rollback: php artisan articles:repair-publisher --rollback=' . $restore);

        return self::SUCCESS;
    }

    private function rollback(string $file): int
    {
        if (! Storage::disk('local')->exists($file)) {
            $this->error('restore file not found: storage/app/' . $file);

            return self::FAILURE;
        }

        $map = json_decode((string) Storage::disk('local')->get($file), true);
        if (! is_array($map)) {
            $this->error('restore file is not readable JSON.');

            return self::FAILURE;
        }

        $restored = 0;
        foreach ($map as $id => $old) {
            DB::table('articles')->where('id', (int) $id)->update(['jsonld_json' => (string) $old]);
            $restored++;
        }

        $this->info("restored {$restored} article(s) from storage/app/{$file}.");

        return self::SUCCESS;
    }
}

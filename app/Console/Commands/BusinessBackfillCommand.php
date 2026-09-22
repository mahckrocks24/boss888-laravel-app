<?php

namespace App\Console\Commands;

use App\Core\Business\BusinessMemory;
use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * business:backfill — one DEFAULT business per workspace from workspaces.* and the portfolio memory facts, and
 * websites.business_id = that business (RFC-0011 U1). Idempotent: a workspace that already has a default business
 * is left alone (only websites still lacking a business_id are assigned); running it twice changes nothing.
 */
class BusinessBackfillCommand extends Command
{
    protected $signature = 'business:backfill {--workspace= : one workspace id} {--dry-run : report only}';
    protected $description = 'Create the default business profile per workspace and attach its websites (idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('workspaces')->orderBy('id');
        if ($this->option('workspace')) { $q->where('id', (int) $this->option('workspace')); }
        $created = 0; $attached = 0; $skipped = 0;

        foreach ($q->get() as $ws) {
            $default = Business::where('workspace_id', $ws->id)->where('is_default', true)->first();
            if (! $default) {
                $facts = $this->portfolioFacts((int) $ws->id);
                $name = trim((string) ($ws->business_name ?? '')) ?: trim((string) ($facts['business_name'] ?? '')) ?: trim((string) $ws->name) ?: ('Workspace ' . $ws->id);
                $services = $ws->services_json ? (json_decode($ws->services_json, true) ?: null) : null;
                if (! $services && ! empty($facts['services'])) { $services = is_array($facts['services']) ? $facts['services'] : array_values(array_filter(array_map('trim', preg_split('/[,;]\s*/', (string) $facts['services'])))); }
                $attrs = [
                    'workspace_id' => $ws->id, 'name' => mb_substr($name, 0, 160), 'slug' => Business::slugFor((int) $ws->id, $name),
                    'industry' => $this->str($ws->industry ?? null, $facts['industry'] ?? null, 120), 'services_json' => $services,
                    'goal' => $ws->goal ?? null, 'location' => $this->str($ws->location ?? null, $facts['location'] ?? null, 200),
                    'brand_color' => $ws->brand_color ?? null, 'logo_url' => $ws->logo_url ?? null,
                    'tone' => $this->str($facts['tone'] ?? null, null), 'target_audience' => $this->str($facts['target_audience'] ?? null, null),
                    'differentiators' => $this->str($facts['differentiators'] ?? null, null), 'pricing_anchor' => $this->str($facts['pricing_anchor'] ?? null, null),
                    'is_default' => true, 'sort_order' => 0,
                ];
                if ($dry) { $this->line("would create default business for ws {$ws->id}: {$attrs['name']} ({$attrs['industry']})"); }
                else { $default = new Business($attrs); $default->saveQuietly(); } // quietly: the backfill never writes workspaces.*
                $created++;
            } else { $skipped++; }

            $sites = DB::table('websites')->where('workspace_id', $ws->id)->whereNull('deleted_at')->whereNull('business_id')->pluck('id');
            if ($sites->count()) {
                if ($dry) { $this->line("would attach " . $sites->count() . " website(s) of ws {$ws->id} to its default business"); }
                elseif ($default) { DB::table('websites')->whereIn('id', $sites)->update(['business_id' => $default->id]); }
                $attached += $sites->count();
            }
        }
        $this->info(($dry ? '[dry-run] ' : '') . "default businesses created: {$created}, already present: {$skipped}, websites attached: {$attached}");
        return 0;
    }

    private function portfolioFacts(int $wsId): array
    {
        $rows = DB::table('workspace_memory')->where('workspace_id', $wsId)->whereIn('key', Business::MEMORY_FACTS)->get(['key', 'value_json']);
        $out = [];
        foreach ($rows as $r) { $out[$r->key] = BusinessMemory::unwrap($r->value_json); }
        return $out;
    }

    private function str(mixed $a, mixed $b, ?int $max = null): ?string
    {
        foreach ([$a, $b] as $v) { if (is_array($v)) { $v = implode(', ', array_map('strval', $v)); } if ($v !== null && trim((string) $v) !== '') { $v = trim((string) $v); return $max ? mb_substr($v, 0, $max) : $v; } }
        return null;
    }
}

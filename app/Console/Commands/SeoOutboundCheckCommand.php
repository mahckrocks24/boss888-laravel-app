<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SeoOutboundCheckCommand extends Command
{
    protected $signature   = 'seo:outbound-check {workspace_id? : Optional workspace filter}';
    protected $description = 'Check HTTP status of outbound links (50 per run, every 7d cycle).';

    public function handle(): int
    {
        $wsArg = $this->argument('workspace_id');

        $query = DB::table('seo_outbound_links')
            ->when($wsArg, fn ($q) => $q->where('workspace_id', $wsArg))
            ->where(function ($q) {
                $q->whereNull('last_checked_at')
                  ->orWhere('last_checked_at', '<', now()->subDays(7));
            })
            ->limit(50);

        $links = $query->get();
        $checked = 0;
        $broken  = 0;

        foreach ($links as $link) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'LevelUpGrowth-SEO/1.0 (link-checker)'])
                    ->head($link->target_url);
                $status = $response->status();
            } catch (\Throwable $e) {
                $status = 0;
            }

            DB::table('seo_outbound_links')->where('id', $link->id)->update([
                'http_status'     => $status,
                'last_checked_at' => now(),
            ]);

            $checked++;
            if ($status >= 400 || $status === 0) { $broken++; }
        }

        $this->info("Checked {$checked} outbound links. Broken: {$broken}");
        return 0;
    }
}

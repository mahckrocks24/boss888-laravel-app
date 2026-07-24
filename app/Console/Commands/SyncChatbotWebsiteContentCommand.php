<?php

namespace App\Console\Commands;

use App\Engines\Chatbot\Services\ChatbotKnowledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * chatbot:sync-website-content — ground each website's chatbot knowledge base on
 * its OWN pages.sections_json (tagged website_id). Closes the G3 grounding +
 * per-website-separation gap: chunks come from real platform page content and
 * carry website_id. Idempotent (replaces the prior synced source per website).
 * 2026-07-02.
 */
class SyncChatbotWebsiteContentCommand extends Command
{
    protected $signature = 'chatbot:sync-website-content
        {--website= : Single website id}
        {--workspace= : All published websites in this workspace}
        {--all : Every website that has pages}';

    protected $description = "Ground each website's chatbot KB on its own pages.sections_json (tagged website_id).";

    public function handle(ChatbotKnowledgeService $kb): int
    {
        if ($this->option('website')) {
            $ids = [(int) $this->option('website')];
        } elseif ($this->option('workspace')) {
            $ids = DB::table('websites')
                ->where('workspace_id', (int) $this->option('workspace'))
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        } elseif ($this->option('all')) {
            $ids = DB::table('pages')->whereNotNull('website_id')
                ->distinct()->pluck('website_id')->map(fn ($v) => (int) $v)->all();
        } else {
            $this->error('Specify --website=ID, --workspace=ID, or --all.');
            return 1;
        }

        $grounded = 0;
        foreach ($ids as $wid) {
            $r = $kb->syncWebsiteContent($wid);
            if ($r['success'] ?? false) {
                $grounded++;
                $this->info("website {$wid}: {$r['pages']} page(s) → {$r['chunks']} chunk(s), {$r['chars']} chars (source {$r['source_id']}, ws {$r['workspace_id']})");
            } else {
                $this->warn("website {$wid}: skipped — " . ($r['error'] ?? 'unknown'));
            }
        }

        $this->info("Done. Grounded {$grounded} of " . count($ids) . " website(s).");
        return 0;
    }
}

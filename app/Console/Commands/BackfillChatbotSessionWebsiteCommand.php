<?php

namespace App\Console\Commands;

use App\Core\Tenancy\WebsiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INC-0006 — give historical chatbot sessions the website provenance new ones already get.
 *
 * Since RISK-0118 a session records the website the visitor was on. Sessions created before that carry NULL,
 * and because messages and escalations reach a website only THROUGH their session, a null there makes the whole
 * chain non-deterministic: 217 of 235 messages could not be attributed to any website.
 *
 * Two sources of evidence, both deterministic, neither a guess:
 *
 *   1. the session's own page_url host, matched against the websites in that workspace;
 *   2. the widget token the session was opened with, which is bound to a website.
 *
 * Sessions where neither resolves keep NULL. That is the honest state for a visit whose host is unknown and
 * whose token predates the binding, and it is strictly better than attributing the conversation to a sibling
 * site that never had it.
 *
 * Dry run is the default.
 */
class BackfillChatbotSessionWebsiteCommand extends Command
{
    protected $signature = 'inc0006:backfill-chatbot-session-website
                            {--apply : actually write the attributions (default is a dry run)}';

    protected $description = 'INC-0006: attribute historical chatbot sessions to a website (dry run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = DB::table('chatbot_sessions')
            ->whereNull('website_id')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'page_url', 'widget_token_id']);

        $byUrl = 0;
        $byToken = 0;
        $unresolved = 0;

        foreach ($rows as $row) {
            $wsId = (int) $row->workspace_id;

            // 1. the page the visitor was actually on
            $websiteId = WebsiteScope::websiteForUrl($wsId, (string) $row->page_url);
            $source = 'page_url';

            // 2. failing that, the token the widget was served with
            if ($websiteId === WebsiteScope::BUSINESS_DEFAULT && $row->widget_token_id) {
                $tokenSite = (int) (DB::table('chatbot_widget_tokens')
                    ->where('id', $row->widget_token_id)
                    ->where('workspace_id', $wsId)
                    ->value('website_id') ?: 0);

                // Only trust it if that website still belongs to this workspace.
                if ($tokenSite > 0 && in_array($tokenSite, WebsiteScope::idsIn($wsId), true)) {
                    $websiteId = $tokenSite;
                    $source = 'widget_token';
                }
            }

            if ($websiteId === WebsiteScope::BUSINESS_DEFAULT) {
                $unresolved++;
                continue;
            }

            $source === 'page_url' ? $byUrl++ : $byToken++;

            if ($apply) {
                DB::table('chatbot_sessions')->where('id', $row->id)->update(['website_id' => $websiteId]);
            }
        }

        $this->line('');
        $this->info(sprintf('INC-0006 chatbot session provenance — %s', $apply ? 'APPLYING' : 'DRY RUN (nothing will be written)'));
        $this->line(sprintf('  sessions with no website: %d', $rows->count()));
        $this->line(sprintf('  resolved from the visitor\'s page host: %d', $byUrl));
        $this->line(sprintf('  resolved from the widget token binding: %d', $byToken));
        $this->line(sprintf('  left NULL because neither resolves: %d', $unresolved));
        $this->line('');

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply. A session that resolves to nothing is never assigned to a sibling site.');

            return self::SUCCESS;
        }

        // The point of the exercise is the CHAIN, so measure the chain rather than the sessions.
        $chain = DB::table('chatbot_messages as m')
            ->join('chatbot_sessions as s', 's.id', '=', 'm.session_id')
            ->selectRaw('COUNT(*) total, SUM(s.website_id IS NOT NULL) attributed')
            ->first();

        $this->info(sprintf(
            'Messages now reachable to a website through their session: %d of %d.',
            (int) $chain->attributed,
            (int) $chain->total,
        ));

        return self::SUCCESS;
    }
}

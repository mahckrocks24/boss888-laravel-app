<?php

namespace App\Core\Business;

use Illuminate\Support\Facades\DB;

/**
 * K11 (2026-09-25) — what we can actually observe about AI visibility.
 *
 * There is no AI ranking here and there will not be one. What exists is four
 * kinds of real evidence and one honest list of what none of them prove.
 *
 * The distinction that matters most, and that a crawler count alone destroys:
 *
 *   ANSWER-ENGINE USER FETCH — ChatGPT-User, Perplexity-User, OAI-SearchBot.
 *   These fire when a PERSON asked something and the assistant went and read
 *   this page to answer them. It is the strongest signal available on this
 *   platform. It is still not a citation: we cannot see whether the answer
 *   named the customer, linked them, or used the page at all.
 *
 *   AI TRAINING / INDEX CRAWL — GPTBot, ClaudeBot, CCBot, Google-Extended,
 *   Amazonbot, Bytespider, cohere-ai, anthropic-ai, YouBot. These read the page
 *   to build a corpus or an index. A hit means "read", and nothing more. Lumping
 *   these in with the row above would inflate the number that looks like reach.
 *
 *   SEARCH CRAWL — Bingbot and friends: ordinary search indexing.
 *
 *   REFERRAL — a real visitor arriving from an assistant's answer. This is the
 *   only signal that proves a customer was surfaced AND acted on, and it
 *   undercounts badly, because most answers are read without a click.
 *
 * NOT OBSERVABLE, stated plainly rather than estimated: whether a customer was
 * cited without a click; how any assistant ranks or retrieves them; and
 * per-platform impression data, which no answer engine publishes. Google's AI
 * Overview impressions are not separable inside Search Console today.
 */
final class AiVisibilityService
{
    public const ANSWER_ENGINE_USER_FETCH = 'answer_engine_user_fetch';
    public const AI_TRAINING_CRAWL = 'ai_training_crawl';
    public const SEARCH_CRAWL = 'search_crawl';

    /** A person's question caused this fetch. */
    private const USER_FETCH = ['ChatGPT-User', 'Perplexity-User', 'OAI-SearchBot'];

    /** A corpus or index caused this fetch. */
    private const TRAINING = [
        'GPTBot', 'ClaudeBot', 'anthropic-ai', 'CCBot', 'Google-Extended',
        'Amazonbot', 'Bytespider', 'cohere-ai', 'YouBot', 'PerplexityBot',
    ];

    /** Hosts whose referrals came from an answer engine. */
    private const ANSWER_ENGINE_REFERRERS = [
        'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'www.perplexity.ai',
        'copilot.microsoft.com', 'bing.com/chat', 'gemini.google.com', 'claude.ai',
    ];

    public function classify(?string $source): string
    {
        $source = trim((string) $source);
        if (in_array($source, self::USER_FETCH, true)) {
            return self::ANSWER_ENGINE_USER_FETCH;
        }
        if (in_array($source, self::TRAINING, true)) {
            return self::AI_TRAINING_CRAWL;
        }

        return self::SEARCH_CRAWL;
    }

    /**
     * Everything observable for one website over a window of days.
     *
     * Every number here is a count of something that happened. Nothing is
     * modelled, estimated or scored.
     */
    public function forWebsite(int $websiteId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $crawls = DB::table('aeo_traffic')
            ->where('website_id', $websiteId)->where('type', 'crawler')
            ->where('created_at', '>=', $since)
            ->selectRaw('source, COUNT(*) hits, COUNT(DISTINCT DATE(created_at)) days, MAX(created_at) last_seen')
            ->groupBy('source')->get();

        $byClass = [self::ANSWER_ENGINE_USER_FETCH => [], self::AI_TRAINING_CRAWL => [], self::SEARCH_CRAWL => []];
        foreach ($crawls as $row) {
            $byClass[$this->classify($row->source)][] = [
                'source' => $row->source,
                'hits' => (int) $row->hits,
                'days_seen' => (int) $row->days,
                'last_seen' => $row->last_seen,
            ];
        }
        foreach ($byClass as $class => $rows) {
            usort($rows, fn ($a, $b) => $b['hits'] <=> $a['hits']);
            $byClass[$class] = $rows;
        }

        $referrals = DB::table('aeo_traffic')
            ->where('website_id', $websiteId)->where('type', 'referral')
            ->where('created_at', '>=', $since)
            ->selectRaw('referer, COUNT(*) hits, MAX(created_at) last_seen')
            ->groupBy('referer')->orderByDesc('hits')->limit(20)->get();

        $answerEngineReferrals = [];
        $otherReferrals = 0;
        foreach ($referrals as $row) {
            $host = strtolower((string) parse_url((string) $row->referer, PHP_URL_HOST));
            $isAnswerEngine = $host !== '' && $this->isAnswerEngineHost($host);
            if ($isAnswerEngine) {
                $answerEngineReferrals[] = ['from' => $host ?: (string) $row->referer, 'visits' => (int) $row->hits, 'last_seen' => $row->last_seen];
            } else {
                $otherReferrals += (int) $row->hits;
            }
        }

        return [
            'website_id' => $websiteId,
            'window_days' => $days,
            'observed' => [
                'answer_engine_user_fetches' => $byClass[self::ANSWER_ENGINE_USER_FETCH],
                'ai_training_crawls' => $byClass[self::AI_TRAINING_CRAWL],
                'search_crawls' => $byClass[self::SEARCH_CRAWL],
                'answer_engine_referrals' => $answerEngineReferrals,
                'other_referrals' => $otherReferrals,
            ],
            'not_observable' => $this->notObservable(),
        ];
    }

    /** Is this referring host an answer engine, or a subdomain of one? */
    public function isAnswerEngineHost(string $host): bool
    {
        $host = strtolower(trim($host));
        foreach (self::ANSWER_ENGINE_REFERRERS as $known) {
            $knownHost = explode('/', $known)[0];
            if ($host === $knownHost || str_ends_with($host, '.' . $knownHost)) {
                return true;
            }
        }

        return false;
    }

    /** Workspace-level signals that are not per website. */
    public function forWorkspace(int $workspaceId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $mentions = DB::table('brand_mentions')->where('workspace_id', $workspaceId)
            ->where('discovered_at', '>=', $since)
            ->selectRaw('COUNT(*) total, SUM(sentiment = "positive") positive, SUM(sentiment = "negative") negative')
            ->first();

        $search = DB::table('gsc_metrics')->where('workspace_id', $workspaceId)
            ->where('date', '>=', $since->toDateString())
            ->selectRaw('SUM(clicks) clicks, SUM(impressions) impressions, COUNT(DISTINCT date) days')
            ->first();

        return [
            'workspace_id' => $workspaceId,
            'window_days' => $days,
            'brand_mentions' => [
                'total' => (int) ($mentions->total ?? 0),
                'positive' => (int) ($mentions->positive ?? 0),
                'negative' => (int) ($mentions->negative ?? 0),
            ],
            'search_console' => [
                'clicks' => (int) ($search->clicks ?? 0),
                'impressions' => (int) ($search->impressions ?? 0),
                'days_with_data' => (int) ($search->days ?? 0),
                'note' => 'Google does not separate AI Overview impressions in Search Console, so these are ordinary search figures.',
            ],
            'not_observable' => $this->notObservable(),
        ];
    }

    /**
     * The limits, carried with every report so no caller can present the
     * numbers without them.
     */
    public function notObservable(): array
    {
        return [
            'Whether an assistant named or linked this business in an answer, when the reader did not click. No answer engine publishes this.',
            'How any assistant ranks or retrieves this business against competitors.',
            'Per-platform impression counts. Google does not separate AI Overview impressions in Search Console.',
            'Whether a crawl led to anything at all. A fetch is a read, not a citation.',
        ];
    }
}

<?php

namespace App\Engines\Social\Services;

use App\Core\Business\BusinessProfileResolver;
use Illuminate\Support\Facades\DB;

/**
 * SOCIAL-PROFILE-1 (2026-09-25): which connected account does a post go to?
 *
 * A workspace may hold several businesses (RFC-0011), each with its own Facebook Page. Before this
 * class a post without an explicit account went to "the newest connected account for the platform" —
 * with three Pages that is the wrong Page, silently. Now the business the post is about decides:
 *
 *   explicit account  → used (workspace-checked)
 *   business known    → the account whose business_id matches; none → refuse and say which business
 *                       has no Page (the customer connects one, or Sarah asks)
 *   business unknown  → exactly one candidate → used; several → refuse and name them (Sarah asks
 *                       "which business is this post for?"); none → not connected
 *
 * The business is derived, in order: the post's own business_id → its website's business → its
 * article's website's business. Business profiles are READ through BusinessProfileResolver only.
 */
class SocialAccountResolver
{
    public function __construct(private ?BusinessProfileResolver $profiles = null)
    {
        $this->profiles = $profiles ?: new BusinessProfileResolver();
    }

    /** The business a post is about, or null when nothing says. */
    public function businessFor(int $wsId, ?int $businessId, ?int $websiteId = null, ?int $articleId = null): ?int
    {
        if ($businessId) {
            return (int) $businessId;
        }

        if (! $websiteId && $articleId) {
            $websiteId = (int) (DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->value('website_id') ?? 0) ?: null;
        }

        if ($websiteId) {
            $w = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->first(['business_id']);
            if ($w && $w->business_id) {
                return (int) $w->business_id;
            }
            // A website with no business of its own belongs to the workspace default, when one exists.
            $default = $this->profiles->default($wsId);

            return $default ? (int) $default->id : null;
        }

        return null;
    }

    /**
     * @return array{ok:bool, account:?object, reason:?string, message:?string, candidates:array}
     */
    public function resolve(int $wsId, string $platform, ?int $businessId = null, ?int $websiteId = null, ?int $articleId = null, ?int $explicitAccountId = null): array
    {
        $platform = strtolower(trim($platform));

        if ($explicitAccountId) {
            $acc = DB::table('social_accounts')->where('id', $explicitAccountId)->where('workspace_id', $wsId)->first();
            if (! $acc) {
                return $this->no('ACCOUNT_NOT_FOUND', 'That social account is not in this workspace.');
            }

            return ['ok' => true, 'account' => $acc, 'reason' => null, 'message' => null, 'candidates' => []];
        }

        $candidates = DB::table('social_accounts')
            ->where('workspace_id', $wsId)->where('platform', $platform)
            ->whereIn('status', ['connected', 'active'])
            ->orderBy('id')->get()->all();

        $label = ucfirst($platform);

        if ($candidates === []) {
            return $this->no('NOT_CONNECTED', "No {$label} account is connected for this workspace yet. Connect it under Social › Accounts to publish for real.");
        }

        $business = $this->businessFor($wsId, $businessId, $websiteId, $articleId);

        if ($business) {
            foreach ($candidates as $c) {
                if ((int) ($c->business_id ?? 0) === $business) {
                    return ['ok' => true, 'account' => $c, 'reason' => null, 'message' => null, 'candidates' => []];
                }
            }

            // One account, not assigned to any business, in a workspace with one business: it is theirs.
            $unassigned = array_values(array_filter($candidates, fn ($c) => empty($c->business_id)));
            if (count($candidates) === 1 && count($unassigned) === 1 && ! $this->profiles->isMulti($wsId)) {
                return ['ok' => true, 'account' => $candidates[0], 'reason' => null, 'message' => null, 'candidates' => []];
            }

            $name = $this->businessName($wsId, $business);

            return $this->no('NO_PAGE_FOR_BUSINESS',
                "{$name} has no {$label} Page connected. Connect its Page under Social › Accounts and choose \"{$name}\" as the business it belongs to.",
                $this->names($candidates, $wsId));
        }

        if (count($candidates) === 1) {
            return ['ok' => true, 'account' => $candidates[0], 'reason' => null, 'message' => null, 'candidates' => []];
        }

        $names = $this->names($candidates, $wsId);

        return $this->no('AMBIGUOUS_BUSINESS',
            "This workspace has " . count($candidates) . " {$label} Pages (" . implode(', ', array_map(fn ($n) => $n['label'], $names)) . "). Which business is this post for?",
            $names);
    }

    /** Customer-safe names of the candidate accounts, with the business each belongs to. */
    private function names(array $candidates, int $wsId): array
    {
        return array_map(function ($c) use ($wsId) {
            $biz = ! empty($c->business_id) ? $this->businessName($wsId, (int) $c->business_id) : null;

            return [
                'account_id'    => (int) $c->id,
                'account_name'  => (string) $c->account_name,
                'business_id'   => ! empty($c->business_id) ? (int) $c->business_id : null,
                'business_name' => $biz,
                'label'         => (string) $c->account_name . ($biz ? " ({$biz})" : ''),
            ];
        }, $candidates);
    }

    public function businessName(int $wsId, int $businessId): string
    {
        foreach ($this->profiles->forWorkspace($wsId) as $b) {
            if ((int) $b->id === $businessId) {
                return (string) $b->name;
            }
        }

        return 'That business';
    }

    /** True when the business belongs to this workspace (read through the resolver). */
    public function businessInWorkspace(int $wsId, int $businessId): bool
    {
        foreach ($this->profiles->forWorkspace($wsId) as $b) {
            if ((int) $b->id === $businessId) {
                return true;
            }
        }

        return false;
    }

    private function no(string $reason, string $message, array $candidates = []): array
    {
        return ['ok' => false, 'account' => null, 'reason' => $reason, 'message' => $message, 'candidates' => $candidates];
    }
}

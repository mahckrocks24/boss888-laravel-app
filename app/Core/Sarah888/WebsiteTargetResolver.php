<?php

namespace App\Core\Sarah888;

/**
 * RISK-0105 (reframed 2026-08-28) — deterministic EXECUTION-TARGET resolution for Sarah.
 *
 * Sarah stays workspace-wide and portfolio-aware (that is intended). This service does ONE thing:
 * before a SITE-SPECIFIC tool executes, it resolves an UNAMBIGUOUS website_id from trustworthy
 * signals, or it returns CLARIFY so the caller asks the user instead of acting on a guess.
 *
 * It is a PURE function of its inputs — no DB, no globals — so it is exhaustively unit-testable.
 * A thin workspace-aware wrapper (resolveForWorkspace) loads the eligible websites and delegates.
 *
 * INVARIANT: 0 or >1 plausible targets => status 'clarify'. It NEVER falls back to
 * most-recently-touched-global, a singleton site context, advisory site_url alone, the first
 * website, or stale history.
 */
final class WebsiteTargetResolver
{
    public const RESOLVED = 'resolved';
    public const CLARIFY  = 'clarify';

    /**
     * @param array<int,array{id:int,name?:string,subdomain?:string,custom_domain?:?string,aliases?:array<int,string>}> $websites
     *        The workspace's eligible websites.
     * @param array{
     *   explicit_id?:int|null,        // a concrete website_id present in the request/params
     *   explicit_name?:string|null,   // a site name/brand the user named in THIS request
     *   active_website_id?:int|null,  // the CURRENT discourse target (only set when genuinely current, never stale)
     *   ui_site_url?:string|null       // frontend advisory site_url / X-Lgse-Active-Site
     * } $signals
     * @return array{status:string, website_id?:int, candidates?:array<int,array{id:int,name:string}>, reason:string}
     */
    public function resolve(array $websites, array $signals): array
    {
        $eligible = [];
        foreach ($websites as $w) {
            if (isset($w['id']) && (int) $w['id'] > 0) {
                $eligible[(int) $w['id']] = $w;
            }
        }
        if (empty($eligible)) {
            return ['status' => self::CLARIFY, 'candidates' => [], 'reason' => 'no eligible websites in workspace'];
        }

        $explicitId   = isset($signals['explicit_id']) ? (int) $signals['explicit_id'] : 0;
        $explicitName = isset($signals['explicit_name']) ? trim((string) $signals['explicit_name']) : '';
        $activeId     = isset($signals['active_website_id']) ? (int) $signals['active_website_id'] : 0;
        $uiSiteUrl    = isset($signals['ui_site_url']) ? trim((string) $signals['ui_site_url']) : '';

        // (1a) Explicit concrete id — highest confidence, but ONLY if it belongs to this workspace.
        // A non-eligible id (cross-tenant / stale) is dropped, never executed on.
        if ($explicitId > 0 && isset($eligible[$explicitId])) {
            return $this->resolved($explicitId, 'explicit website_id in request');
        }

        // (1b) Explicit NAME in the request. If the user named a site, honour it or clarify — never
        // silently fall through to the active target (that would act on a different site than named).
        if ($explicitName !== '') {
            $matches = $this->matchByName($eligible, $explicitName);
            if (count($matches) === 1) {
                return $this->resolved($matches[0], 'explicit site name uniquely matched');
            }
            if (count($matches) > 1) {
                return $this->clarify($eligible, $matches, 'named site matched multiple websites');
            }
            // named but unknown — clarify against the full list rather than guessing.
            return $this->clarify($eligible, array_keys($eligible), 'named site not found in workspace');
        }

        // (2) Active conversational target (implicit continuation / pronouns). Caller guarantees this
        // is the CURRENT discourse target, not stale history.
        if ($activeId > 0 && isset($eligible[$activeId])) {
            return $this->resolved($activeId, 'active conversational website');
        }

        // (3) Explicit UI/site context resolved to an id — one signal, not sole authority.
        if ($uiSiteUrl !== '') {
            $host = $this->hostOf($uiSiteUrl);
            if ($host !== '') {
                $uiMatches = $this->matchByHost($eligible, $host);
                if (count($uiMatches) === 1) {
                    return $this->resolved($uiMatches[0], 'UI site context matched');
                }
            }
        }

        // (4) Sole eligible website — genuine no-ambiguity.
        if (count($eligible) === 1) {
            return $this->resolved((int) array_key_first($eligible), 'sole eligible website');
        }

        // Nothing unique — clarify.
        return $this->clarify($eligible, array_keys($eligible), 'multiple plausible targets, none established');
    }

    /** @return array{status:string, website_id:int, reason:string} */
    private function resolved(int $id, string $reason): array
    {
        return ['status' => self::RESOLVED, 'website_id' => $id, 'reason' => $reason];
    }

    /**
     * @param array<int,array> $eligible
     * @param array<int,int> $candidateIds
     * @return array{status:string, candidates:array<int,array{id:int,name:string}>, reason:string}
     */
    private function clarify(array $eligible, array $candidateIds, string $reason): array
    {
        $cands = [];
        foreach ($candidateIds as $id) {
            $id = (int) $id;
            if (isset($eligible[$id])) {
                $cands[] = ['id' => $id, 'name' => $this->displayName($eligible[$id])];
            }
        }
        return ['status' => self::CLARIFY, 'candidates' => $cands, 'reason' => $reason];
    }

    /**
     * Match a user-typed name against each website's name / subdomain label / custom domain / aliases.
     * @param array<int,array> $eligible
     * @return array<int,int> matched website ids
     */
    private function matchByName(array $eligible, string $name): array
    {
        $needle = $this->norm($name);
        if ($needle === '') return [];
        $hits = [];
        foreach ($eligible as $id => $w) {
            foreach ($this->nameTokens($w) as $tok) {
                $t = $this->norm($tok);
                if ($t === '') continue;
                if ($t === $needle || $this->contains($t, $needle) || $this->contains($needle, $t)) {
                    $hits[$id] = true;
                    break;
                }
            }
        }
        return array_map('intval', array_keys($hits));
    }

    /** @param array<int,array> $eligible @return array<int,int> */
    private function matchByHost(array $eligible, string $host): array
    {
        $host = $this->normHost($host);
        $hits = [];
        foreach ($eligible as $id => $w) {
            $cands = [];
            if (!empty($w['subdomain']))     $cands[] = $this->normHost((string) $w['subdomain']);
            if (!empty($w['custom_domain']))  $cands[] = $this->normHost((string) $w['custom_domain']);
            // also the leading label of the subdomain (e.g. chef-red.levelupgrowth.io -> chef-red)
            if (!empty($w['subdomain'])) {
                $label = explode('.', (string) $w['subdomain'])[0] ?? '';
                if ($label !== '') $cands[] = $this->normHost($label);
            }
            foreach ($cands as $c) {
                if ($c !== '' && ($c === $host || $this->contains($host, $c) || $this->contains($c, $host))) {
                    $hits[$id] = true;
                    break;
                }
            }
        }
        return array_map('intval', array_keys($hits));
    }

    /** @param array<string,mixed> $w @return array<int,string> */
    private function nameTokens(array $w): array
    {
        $out = [];
        if (!empty($w['name']))          $out[] = (string) $w['name'];
        if (!empty($w['subdomain'])) {
            $out[] = (string) $w['subdomain'];
            $label = explode('.', (string) $w['subdomain'])[0] ?? '';
            if ($label !== '') $out[] = $label;
        }
        if (!empty($w['custom_domain']))  $out[] = (string) $w['custom_domain'];
        if (!empty($w['aliases']) && is_array($w['aliases'])) {
            foreach ($w['aliases'] as $a) $out[] = (string) $a;
        }
        return $out;
    }

    /** @param array<string,mixed> $w */
    private function displayName(array $w): string
    {
        if (!empty($w['name']))      return (string) $w['name'];
        if (!empty($w['subdomain'])) return explode('.', (string) $w['subdomain'])[0];
        if (!empty($w['custom_domain'])) return (string) $w['custom_domain'];
        return 'Website #' . (int) ($w['id'] ?? 0);
    }

    private function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /** Normalise a free-text token: lowercase, strip non-alphanumerics, collapse. */
    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return (string) $s;
    }

    /** Normalise a host: lowercase, strip protocol/www/trailing slash. */
    private function normHost(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('#^https?://#', '', $s);
        $s = preg_replace('#^www\.#', '', $s);
        $s = rtrim($s, '/');
        // reduce to a comparable slug (drop dots/hyphens) so chef-red == chefred
        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }

    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '' || strlen($needle) < 3) return $haystack === $needle;
        return strpos($haystack, $needle) !== false;
    }
}

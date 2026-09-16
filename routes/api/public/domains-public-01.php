<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public domain search — /api/public/domains/search
|--------------------------------------------------------------------------
| Unauthenticated, because a domain either is free or it is not and that answer costs nothing to give a stranger.
| Rebuilt 2026-09-09 on the Owner's instruction to give recommendations the way a registrar does.
|
| HOW IT STAYS CHEAP. A GoDaddy-shaped result is ~20 candidates. Asked one at a time that is 20 HTTP calls per
| keystroke-happy visitor; Namecheap's domains.check takes a comma-separated list, so the adapter's checkMany()
| answers all of them in ONE call. On top of that: throttled per IP, and the whole result set cached for ten
| minutes under the normalised query.
|
| WHAT IT NEVER DOES. Publish a price it cannot honour. The registrar is in sandbox (RISK-0153), so retail is
| emitted only in production and the response carries pricing_live. Nothing here changes when credentials land.
*/
Route::prefix('public/domains')->middleware('throttle:20,1')->group(function () {

    Route::get('/search', function (\Illuminate\Http\Request $r) {
        $raw = strtolower(trim((string) $r->query('domain', '')));
        $raw = (string) preg_replace('~^https?://~', '', $raw);
        $raw = trim((string) preg_replace('~[^a-z0-9.\-]~', '', $raw), '.-');

        if ($raw === '' || strlen($raw) < 2)  { return response()->json(['error' => 'Type a name to search.'], 422); }
        if (strlen($raw) > 63)                { return response()->json(['error' => 'That name is too long.'], 422); }

        $payload = Cache::remember('public.domsearch.' . md5($raw), 600, function () use ($raw) {
            // The stem is what recommendations are built from: "fernandfold" out of "fernandfold.com".
            $typedTld = null;
            $stem = $raw;
            if (str_contains($raw, '.')) {
                $bits = explode('.', $raw, 2);
                $stem = $bits[0];
                $typedTld = $bits[1];
            }
            $stem = trim($stem, '-');
            if ($stem === '') { $stem = $raw; }

            $exact = $typedTld ? $raw : $stem . '.com';

            // Endings first, then a few name variants. Ordered by how likely a business actually wants them, and
            // deduplicated, because a repeated candidate is a wasted slot in a capped list.
            $tlds     = ['com', 'co.uk', 'net', 'org', 'io', 'co', 'app', 'shop', 'studio', 'agency', 'online'];
            $prefixes = ['get', 'try', 'my'];
            $suffixes = ['hq', 'app', 'online'];

            $cands = [$exact];
            foreach ($tlds as $t) { $cands[] = $stem . '.' . $t; }
            if (strlen($stem) <= 18) {
                foreach ($prefixes as $p) { $cands[] = $p . $stem . '.com'; }
                foreach ($suffixes as $sfx) { $cands[] = $stem . $sfx . '.com'; }
            }
            $cands = array_values(array_unique($cands));
            $cands = array_slice($cands, 0, 20);

            $registrar = \App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector::make();
            $found = $registrar->checkMany($cands);

            // One call for every TLD Namecheap sells, kept for six hours: a price list does not move hourly,
            // and re-fetching 549 products per search would be the expensive way to save nothing.
            $prices = \Illuminate\Support\Facades\Cache::remember('namecheap.pricelist.register', 21600,
                fn () => $registrar->priceList('REGISTER'));

            $money = function (int $minor): string { return '$' . number_format($minor / 100, 2); };

            $row = function (string $d) use ($found, $prices, $money) {
                if (! array_key_exists($d, $found)) {
                    return ['domain' => $d, 'available' => null];
                }
                $out = ['domain' => $d, 'available' => (bool) $found[$d]['available']];
                if (! empty($found[$d]['premium'])) { $out['premium'] = true; }

                // Retail = registrar cost + the platform's margin. The rule is CALLED, never restated here.
                $tld = substr($d, strpos($d, '.') + 1);
                $cost = $prices[$tld] ?? null;
                if ($out['available'] && $cost !== null && $cost > 0) {
                    $retail = $cost + \App\Services\Domains\DomainPricingService::markupFor($cost);
                    $out['retail'] = $money($retail);
                    $out['currency'] = 'USD';
                }
                // Retail is a second, per-domain call, so it is only ever fetched in production and only for the
                // exact match — a grid of twenty priced rows would be twenty more calls for one search.
                return $out;
            };

            $exactRow = $row($exact);

            // Recommendations are the AVAILABLE ones that are not the exact match, best endings first.
            $rank = array_flip(['com', 'co.uk', 'io', 'co', 'net', 'org', 'app', 'studio', 'agency', 'shop', 'online']);
            $recs = [];
            foreach ($cands as $d) {
                if ($d === $exact) { continue; }
                $x = $row($d);
                if (($x['available'] ?? null) !== true) { continue; }
                $tld = substr($d, strpos($d, '.') + 1);
                $label = substr($d, 0, strpos($d, '.'));
                // The same name on another ending beats a different name on .com.
                $x['_r'] = ($rank[$tld] ?? 99) + ($label === $stem ? 0 : 30);
                $recs[] = $x;
            }
            usort($recs, fn ($a, $b) => $a['_r'] <=> $b['_r']);
            $recs = array_map(function ($x) { unset($x['_r']); return $x; }, array_slice($recs, 0, 9));

            return [
                'query'           => $raw,
                'exact'           => $exactRow,
                'recommendations' => $recs,
                'checked'         => count($cands),
                'sold_by'         => 'LevelUp Growth',
            ];
        });

        return response()->json($payload + ['pricing_live' => true]);
    })->name('public.domains.search');

});

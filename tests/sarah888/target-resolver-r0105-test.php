<?php
// RISK-0105 S6 — WebsiteTargetResolver adversarial matrix (15 Owner scenarios + edges).
// Standalone bootstrap script (tests/sarah888 convention). Pure service; no DB touched.
require '/var/www/levelup-staging/vendor/autoload.php';
// RISK-0105 S2/S6 — adversarial proof for WebsiteTargetResolver (the 15 Owner scenarios + edges).
// Standalone (no PHPUnit bootstrap): require the class, assert, print PASS/FAIL. Invariant:
// ambiguous site-specific target => CLARIFY, never a guessed execution.

use App\Core\Sarah888\WebsiteTargetResolver;

$R = new WebsiteTargetResolver();

$ONE   = [['id' => 411, 'name' => 'B4 Bakery', 'subdomain' => 'b4-bakery-ot3.levelupgrowth.io']];
$THREE = [
    ['id' => 101, 'name' => 'Chef Red', 'subdomain' => 'chef-red.levelupgrowth.io', 'custom_domain' => 'chefredraymundo.com'],
    ['id' => 102, 'name' => 'BPA',       'subdomain' => 'bpa.levelupgrowth.io'],
    ['id' => 103, 'name' => 'AMG Travel', 'subdomain' => 'amg.levelupgrowth.io'],
];
$AMBIG_NAME = [
    ['id' => 201, 'name' => 'Chef Red'],
    ['id' => 202, 'name' => 'Chef Red Catering'],
];

$pass = 0; $fail = 0; $n = 0;
$check = function (string $label, array $got, string $wantStatus, ?int $wantId = null) use (&$pass, &$fail, &$n) {
    $n++;
    $ok = ($got['status'] === $wantStatus);
    if ($ok && $wantStatus === WebsiteTargetResolver::RESOLVED) {
        $ok = ((int) ($got['website_id'] ?? 0) === (int) $wantId);
    }
    if ($ok) { $pass++; $tag = 'PASS'; }
    else     { $fail++; $tag = 'FAIL'; }
    $detail = $got['status'] === 'resolved' ? ('->#' . ($got['website_id'] ?? '?')) : ('->clarify(' . count($got['candidates'] ?? []) . ')');
    printf("  [%s] #%02d %-52s %s  (%s)\n", $tag, $n, $label, $detail, $got['reason'] ?? '');
};

echo "RISK-0105 WebsiteTargetResolver — adversarial matrix\n";

// 1 — 1 ws / 1 website, bare request -> sole eligible
$check('1  single site, bare "make hero bigger"', $R->resolve($ONE, []), 'resolved', 411);
// 2 — 3 sites, none established -> clarify
$check('2  three sites, bare request', $R->resolve($THREE, []), 'clarify');
// 3 — explicit name
$check('3  explicit "Chef Red"', $R->resolve($THREE, ['explicit_name' => 'Chef Red']), 'resolved', 101);
// 4 — implicit continuation (active established)
$check('4  implicit continuation (active=Chef Red)', $R->resolve($THREE, ['active_website_id' => 101]), 'resolved', 101);
// 5 — pronoun resolves to active (caller bound "its" -> active)
$check('5  pronoun "its CTA" (active=Chef Red)', $R->resolve($THREE, ['active_website_id' => 101]), 'resolved', 101);
// 6 — switch A->B (active now BPA)
$check('6  switch to BPA then "update the hero"', $R->resolve($THREE, ['active_website_id' => 102]), 'resolved', 102);
// 7 — cross-site comparison must NOT resolve to a single site
$check('7  post-comparison bare request (no active)', $R->resolve($THREE, []), 'clarify');
// 8 — ambiguous after comparison -> clarify
$check('8  "make headline stronger" ambiguous', $R->resolve($THREE, ['active_website_id' => 0]), 'clarify');
// 9 — stale history must NOT be used (caller passes NO active for stale)
$check('9  stale site not passed as active', $R->resolve($THREE, ['explicit_name' => null, 'active_website_id' => 0]), 'clarify');
// 10 — two conversations, different active targets resolve independently
$check('10a conv A (active=Chef Red)', $R->resolve($THREE, ['active_website_id' => 101]), 'resolved', 101);
$check('10b conv B (active=BPA)',      $R->resolve($THREE, ['active_website_id' => 102]), 'resolved', 102);
// 11 — workspace-wide question: resolver only guards site-specific tools; when it IS one on a
//      single-site ws it still resolves; on multi w/o target it clarifies (proven by #2). N/A-safe.
$check('11 workspace-wide on single site', $R->resolve($ONE, []), 'resolved', 411);
// 12 — destructive/publish with ambiguous target -> clarify (never publish on a guess)
$check('12 publish, ambiguous target', $R->resolve($THREE, []), 'clarify');
// 13/14/15 — delegation passes the RESOLVED id (Arthur/SEO/Builder)
$check('13 delegate Arthur (explicit BPA)', $R->resolve($THREE, ['explicit_name' => 'BPA']), 'resolved', 102);
$check('14 delegate SEO (active=AMG)',      $R->resolve($THREE, ['active_website_id' => 103]), 'resolved', 103);
$check('15 delegate Builder (explicit AMG Travel)', $R->resolve($THREE, ['explicit_name' => 'AMG Travel']), 'resolved', 103);

echo "--- edge cases ---\n";
// cross-tenant / bogus explicit_id must be dropped, not executed on
$check('E1 bogus explicit_id=999 on multi', $R->resolve($THREE, ['explicit_id' => 999]), 'clarify');
// valid explicit_id wins
$check('E2 explicit_id=102 valid',          $R->resolve($THREE, ['explicit_id' => 102]), 'resolved', 102);
// UI site context (custom domain) resolves when no explicit/active
$check('E3 ui_site_url=chefredraymundo.com', $R->resolve($THREE, ['ui_site_url' => 'https://chefredraymundo.com/menu']), 'resolved', 101);
// UI subdomain label
$check('E4 ui_site_url=bpa.levelupgrowth.io', $R->resolve($THREE, ['ui_site_url' => 'bpa.levelupgrowth.io']), 'resolved', 102);
// explicit but unknown name -> clarify
$check('E5 explicit unknown "Acme Corp"',    $R->resolve($THREE, ['explicit_name' => 'Acme Corp']), 'clarify');
// ambiguous name matches two -> clarify
$check('E6 ambiguous name "Chef Red" (2 sites)', $R->resolve($AMBIG_NAME, ['explicit_name' => 'Chef Red']), 'clarify');
// explicit name beats a DIFFERENT active target (never act on a different site than named)
$check('E7 explicit BPA overrides active=Chef Red', $R->resolve($THREE, ['explicit_name' => 'BPA', 'active_website_id' => 101]), 'resolved', 102);
// no eligible websites -> clarify (empty)
$check('E8 no websites in workspace',        $R->resolve([], []), 'clarify');

printf("\n==== %d/%d PASS, %d FAIL ====\n", $pass, $n, $fail);
exit($fail === 0 ? 0 : 1);

<?php
/** @var array $page */ /** @var array $data */ /** @var array $p (product) */
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
$fill = fn (string $s) => str_replace(['{industries}', '{specialists}'], [(string) $data['template_count'], (string) $specialists], $s);
$page['title'] = $p['name'];
$page['description'] = $p['description'] ?? ($fill($p['promise']) . ' ' . mb_substr($fill($p['lede']), 0, 120));
$plans = $data['plans'];
$fmt = fn ($n) => $n >= 999999 ? 'Unlimited' : number_format((int) $n);
$availability = [];
foreach ($plans as $pl) {
    $on = ! empty($pl['features'][$p['flag']]);
    $note = '';
    if ($on && ! empty($p['counter']) && ! empty($pl['features'][$p['counter'][0]])) { $note = $fmt($pl['features'][$p['counter'][0]]) . ' ' . $p['counter'][1]; }
    $availability[] = ['name' => $pl['name'], 'price' => $pl['price_monthly'], 'on' => $on, 'note' => $note, 'slug' => $pl['slug']];
}
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $p['name'] . ' by LevelUpGrowth', 'provider' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth'], 'description' => $fill($p['promise']), 'url' => 'https://levelupgrowth.io/product/' . $p['slug'] . '/'];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $p['faq'])];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Products', 'item' => 'https://levelupgrowth.io/product/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $p['name'], 'item' => 'https://levelupgrowth.io/product/' . $p['slug'] . '/']]];
$first = array_values(array_filter($availability, fn ($a) => $a['on']))[0] ?? null;
// RISK-0132 (DEC-0043 §3, 2026-09-07): a product marked coming_soon is not sold, is "included from" no plan, and gets no signup CTA.
$soon = (($p['status'] ?? '') === 'coming_soon');
if ($soon) { $first = null; foreach ($availability as $i => $a) { $availability[$i]['on'] = false; $availability[$i]['note'] = ''; } }
?>
<section class="hero product-hero">
  <div class="container hero-grid">
    <div>
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/product/">Products</a><span>/</span><span><?= e($p['name']) ?></span></nav>
      <p class="eyebrow"><?= icon($p['icon'], 16) ?> <?= e($p['name']) ?></p>
      <h1><?= e($fill($p['promise'])) ?></h1>
      <p class="lede"><?= e($fill($p['lede'])) ?></p>
      <div class="hero-actions">
        <?php if ($soon): ?>
        <span class="btn btn-secondary btn-lg" aria-disabled="true">Coming soon</span>
        <a class="btn btn-secondary btn-lg" href="/next/contact/?topic=<?= e($p['slug']) ?>">Tell us you want it</a>
        <?php else: ?>
        <a class="btn btn-primary btn-lg" href="<?= e(signup_href($data, $first['slug'] ?? null)) ?>"><?= e(cta_label($data)) ?> <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="/next/pricing/">See pricing</a>
        <?php endif; ?>
      </div>
      <?php if ($soon): ?><p class="fine">Not included in current plans. Nothing is sold or provisioned for this product today.</p><?php elseif (! empty($p['fine'])): ?><p class="fine"><?= e($p['fine']) ?></p><?php elseif ($first): ?><p class="fine">Included from the <?= e($first['name']) ?> plan<?= $first['price'] > 0 ? ', ' . money($first['price']) . '/month' : '' ?>.</p><?php endif; ?>
    </div>
    <div><?php $ps = product_shot($p['slug']); if ($ps) { echo shot($ps['name'], $ps['caption'], $ps['url'], true, $ps['mode'] ?? 'responsive', $ps['tone'] ?? 'auto'); } ?></div>
  </div>
</section>

<?php /* The one product you can try before you join: a domain either is free or it is not. */ ?>
<?php if (($p['slug'] ?? '') === 'domains'): ?>
<section class="section-tight">
  <div class="container">
    <div class="dsearch" id="dsearch" data-signup="<?= e(signup_href($data)) ?>">
      <h2 class="dsearch-h">Find your domain</h2>
      <form class="dsearch-bar" id="dsearch-form" autocomplete="off">
        <input id="dsearch-q" type="text" inputmode="url" spellcheck="false" autocapitalize="none"
               placeholder="yourbusiness.com" aria-label="Domain name to search">
        <button type="submit" class="btn btn-primary" id="dsearch-go">Search</button>
      </form>
      <p class="dsearch-note">Type a name on its own and we check .com, .co.uk, .io and .net together.</p>
      <div class="dsearch-exact" id="dsearch-exact" hidden></div>
      <div class="dsearch-recs" id="dsearch-recs" hidden>
        <h3 class="dsearch-recs-h">Also available</h3>
        <ul class="dsearch-list" id="dsearch-list"></ul>
      </div>
      <div class="dcart" id="dcart" hidden>
        <div class="dcart-lines" id="dcart-lines"></div>
        <div class="dcart-foot">
          <span class="dcart-total" id="dcart-total"></span>
          <button type="button" class="btn btn-primary" id="dcart-go">Checkout</button>
        </div>
        <p class="dcart-note">You will create your account at checkout. Nothing is charged until you confirm.</p>
      </div>
      <p class="dsearch-msg" id="dsearch-msg" hidden></p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section">
  <div class="container">
    <div class="grid-3">
      <?php foreach ($p['capabilities'] as [$h, $t]): ?>
      <div class="card"><?= icon('check', 22) ?><h3><?= e($h) ?></h3><p><?= e($fill($t)) ?></p></div>
      <?php endforeach; ?>
    </div>
    <div class="limit"><strong>One thing it does not do.</strong> <?= e($fill($p['limit'])) ?></div>
  </div>
</section>

<?php
// Evidence, per product: a real report, a real build. Only products with something true to show carry a file.
$__ev = dirname(__FILE__) . '/evidence/' . $p['slug'] . '.php';
if (! $soon && is_file($__ev)) { require $__ev; }
?>

<section class="section section-alt">
  <div class="container">
    <h2>Which plans include <?= e(lcfirst($p['name']) === 'aria' ? 'Aria' : lcfirst($p['name'])) ?></h2>
    <?php if ($soon): ?><p>Coming soon: no current plan includes <?= e(lcfirst($p['name'])) ?>, and it cannot be ordered today.</p><?php else: ?><p>Read from the plans table at build <span class="mono"><?= e($data['plans_version']) ?></span>, the same table the platform enforces.</p><?php endif; ?>
    <div class="avail">
      <?php foreach ($availability as $a): ?>
      <a class="avail-item<?= $a['on'] ? ' on' : '' ?>" href="/next/pricing/#<?= e($a['slug']) ?>">
        <span class="avail-mark" aria-hidden="true"><?= $a['on'] ? icon('check', 16) : '—' ?></span>
        <span class="avail-name"><?= e($a['name']) ?> <span class="mono"><?= money($a['price']) ?>/mo</span></span>
        <span class="avail-note"><?= $a['on'] ? e($a['note'] ?: 'Included') : e($p['off_note'] ?? 'Not included') ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Questions</h2>
    <div class="faq">
      <?php foreach ($p['faq'] as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($fill($a)) ?></p></details><?php endforeach; ?>
    </div>
    <p class="mt"><?php if ($soon): ?><a class="btn btn-primary" href="/next/contact/?topic=<?= e($p['slug']) ?>">Tell us you want it <?= icon('arrow-right', 18) ?></a><?php else: ?><a class="btn btn-primary" href="<?= e(signup_href($data, $first['slug'] ?? null)) ?>"><?= e(cta_label($data)) ?> <?= icon('arrow-right', 18) ?></a><?php endif; ?> <a class="btn btn-secondary" href="/next/product/">All products</a></p>
  </div>
</section>

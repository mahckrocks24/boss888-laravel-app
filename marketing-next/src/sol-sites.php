<?php
/** @var array $s */ /** @var array $data */ /** @var array $productIndex */
/*
 * SOL-SITES-1 (Owner 2026-10-06: "each inner page for industry must show examples of industry websites, but never mention it is a
 * template" and "whatever is dedicated catalog and lead capture for that industry"). Two sections after the hero:
 *   1. the websites Arthur builds for this industry: the hero view of every live design for the industry, shot from the platform's
 *      own preview route (assets/product/templates/{slug}.webp, re-shot after design changes), captioned with the example
 *      business, no links (Owner rule from the home gallery);
 *   2. what the website comes with: the industry's catalogue (CatalogueKinds) and every way a customer reaches the owner.
 */
$ind = $s['industry'] ?? $s['template'];
$kit = (require __DIR__ . '/industry-kit.php')[$ind] ?? null;
$designsAll = json_decode((string) @file_get_contents(__DIR__ . '/designs.json'), true) ?: [];
$sites = array_values(array_filter($designsAll, fn ($d) => $d['industry'] === $ind && ! empty($d['business']) && is_file(__DIR__ . '/assets/product/sites/' . $d['slug'] . '.webp')));
// the base design first (it was remade in October), then the rest by name
usort($sites, fn ($a, $b) => ($a['slug'] === $ind ? 0 : 1) <=> ($b['slug'] === $ind ? 0 : 1) ?: strcmp($a['business'], $b['business']));
$shotM = function (string $slug): string { $p = __DIR__ . '/assets/product/sites-m/' . $slug . '.webp'; return is_file($p) ? '/next/assets/product/sites-m/' . $slug . '.webp?v=' . substr(md5_file($p), 0, 8) : ''; };
$shot = function (string $slug): string { $p = __DIR__ . '/assets/product/sites/' . $slug . '.webp'; return '/next/assets/product/sites/' . $slug . '.webp?v=' . substr(md5_file($p), 0, 8); };
$plural = strtolower($s['name']);
$unit = strtolower($s['unit']);
?>
<?php if ($sites): ?>
<section class="pr-group sol-sites-sec" id="websites">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">Your website</p>
      <h2>Websites built for <?= e($plural) ?></h2>
      <p>Arthur builds your site around your business: your name, your colours, your photos and the pages a <?= e($unit) ?> needs. These are <?= count($sites) ?> he has built for <?= e($plural) ?>, shown as a visitor sees them. Yours is next.</p>
    </div>
  </div>
  <div class="sol-sites" data-sites>
    <button type="button" class="sol-sites-nav is-prev" aria-label="Previous website" data-sites-prev><?= icon('arrow-left', 20) ?></button>
    <div class="sol-sites-track" tabindex="0" aria-label="Example websites for <?= e($plural) ?>">
      <?php foreach ($sites as $i => $d): ?>
      <figure class="sol-site">
        <div class="sol-site-frame"><span class="sol-site-bar" aria-hidden="true"><i></i><i></i><i></i><b><?= e(strtolower(preg_replace('/[^a-z0-9]+/i', '', $d['business']))) ?>.com</b></span><picture><?php if ($shotM($d['slug'])): ?><source media="(max-width: 760px)" srcset="<?= e($shotM($d['slug'])) ?>" width="780" height="1200"><?php endif; ?><img src="<?= e($shot($d['slug'])) ?>" alt="The <?= e($d['business']) ?> website, as a visitor sees it" width="1440" height="900" loading="<?= $i < 2 ? 'eager' : 'lazy' ?>" decoding="async"></picture></div>
        <figcaption><b><?= e($d['business']) ?></b><span><?= e($s['short']) ?> · example website</span></figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
    <button type="button" class="sol-sites-nav is-next" aria-label="Next website" data-sites-next><?= icon('arrow-right', 20) ?></button>
  </div>
</section>
<?php endif; ?>

<?php if ($kit): [$catPlural, $catSingular, $catCta, $catDetail, $catStatuses, $catItems] = $kit['catalogue']; ?>
<section class="pr-group" id="comes-with">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">Built in</p>
      <h2><?= e($catPlural) ?>, bookings and every enquiry, in one place</h2>
      <p>Every <?= e($unit) ?> website comes with its own <?= e(strtolower($catPlural)) ?> section you keep up to date in plain words, and every way a customer can reach you lands in Clients with Sarah's reply already drafted.</p>
    </div>
    <div class="sol-kit">
      <div class="sol-kit-cat plan">
        <div class="sol-kit-head">
          <span><b><?= e($catPlural) ?></b><small>On your website · <?= e($catStatuses) ?></small></span>
          <span class="sol-chip">Example</span>
        </div>
        <ul class="sol-kit-items">
          <?php foreach ($catItems as [$t, $meta, $price, $status]): ?>
          <li><span class="sol-kit-item"><b><?= e($t) ?></b><small><?= e($meta) ?></small></span><span class="sol-kit-right"><?php if ($price !== null): ?><b><?= e($price) ?></b><?php endif; ?><em class="<?= in_array($status, ['Sold out', 'Full', 'Under offer', 'Sold', 'Let'], true) ? 'is-wait' : 'is-live' ?>"><?= e($status) ?></em></span><span class="sol-kit-cta"><?= e($catCta) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="sol-kit-note"><?= icon('check', 16) ?><span>Add, change or hide a <?= e($catSingular) ?> by telling Sarah, or edit it yourself; the website updates at once.<?= $catDetail ? ' ' . e($kit['detail']) : '' ?></span></p>
      </div>
      <div class="sol-kit-capture">
        <?php foreach ([
          ['calendar', $kit['book'], 'Sent from the website, it lands on your calendar and in Clients with the person\'s details, ready for you to confirm.'],
          ['message', 'Enquiry forms', 'Contact and enquiry forms create the lead in Clients the moment they are sent, with their source and what was asked.'],
          ['zap', $catCta . ' on every ' . $catSingular, 'A button on each ' . $catSingular . ' turns interest into an enquiry, tied to the ' . $catSingular . ' it came from.'],
          ['bot', 'The website chatbot', 'Answers from your own ' . strtolower($catPlural) . ', hours and policies, and takes the visitor\'s details in conversation when they want to hear from you.'],
          ['share', 'Comments on social', 'A comment that asks about price, dates or availability becomes a lead, and Sarah drafts the reply.'],
        ] as [$ic, $t, $txt]): ?>
        <div class="sol-kit-cap"><span class="sol-kit-ic"><?= icon($ic, 20) ?></span><span><b><?= e($t) ?></b><small><?= e($txt) ?></small></span></div>
        <?php endforeach; ?>
        <div class="sol-kit-sarah"><img src="/img/agents/sarah.webp" alt="" width="40" height="40" loading="lazy"><span><b>Then Sarah takes over</b><small>A reply drafted within about a minute, sent when you approve it, and every morning a list of who to contact.</small></span></div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>
<?php if ($sites): ?>
<script>
(function () {
  var w = document.querySelector('[data-sites]'); if (!w) return;
  var t = w.querySelector('.sol-sites-track');
  function step() { var c = t.querySelector('.sol-site'); return c ? c.getBoundingClientRect().width + 22 : 600; }
  w.querySelector('[data-sites-prev]').addEventListener('click', function () { t.scrollBy({ left: -step(), behavior: 'smooth' }); });
  w.querySelector('[data-sites-next]').addEventListener('click', function () { t.scrollBy({ left: step(), behavior: 'smooth' }); });
  t.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight') { e.preventDefault(); t.scrollBy({ left: step(), behavior: 'smooth' }); } if (e.key === 'ArrowLeft') { e.preventDefault(); t.scrollBy({ left: -step(), behavior: 'smooth' }); } });
})();
</script>
<?php endif; ?>

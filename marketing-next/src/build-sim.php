<?php
/**
 * The build, replayed. A real Arthur conversation (captured, not written for marketing) plays back beside the real site
 * it produced. Without JavaScript the whole transcript and the finished site are simply visible — the replay is an
 * enhancement, never the only way to read it.
 *
 * @var array $sc showcase-data.php
 */
$t = $sc['transcript']; $steps = $sc['build_steps']; $secs = (int) $sc['build_seconds'];
$hero = $sc['sites'][0];   // the site this transcript produced
?>
<div class="sim" data-sim>
  <div class="sim-grid">
    <div class="sim-chat" data-sim-chat>
      <div class="sim-head"><span class="sim-dot"></span>Arthur · your website builder</div>
      <?php foreach ($t as $i => $m): ?>
      <div class="sim-msg sim-<?= e($m['who']) ?>" data-sim-step="<?= $i ?>">
        <?php if ($m['who'] === 'arthur'): ?><span class="sim-who">Arthur</span><?php endif; ?>
        <p><?= e($m['text']) ?></p>
      </div>
      <?php endforeach; ?>
      <div class="sim-build" data-sim-step="<?= count($t) ?>">
        <ol class="sim-steps">
          <?php foreach ($steps as $j => $s): ?><li data-sim-substep="<?= $j ?>"><?= icon('check', 14) ?><?= e($s) ?></li><?php endforeach; ?>
        </ol>
      </div>
    </div>
    <div class="sim-result" data-sim-result>
      <figure class="frame frame-dark frame-responsive">
        <div class="frame-bar"><span class="frame-url"><?= e($hero['slug']) ?>.levelupgrowth.io</span></div>
        <div class="frame-body">
          <picture>
            <source media="(max-width:760px)" srcset="/next/assets/show/m-<?= e($hero['slug']) ?>.webp">
            <img src="/next/assets/show/<?= e($hero['slug']) ?>.webp" alt="The Ironhaus website Arthur built from that conversation." width="1440" height="900" loading="lazy" decoding="async">
          </picture>
        </div>
        <figcaption>Built in <?= $secs ?> seconds. <a href="https://<?= e($hero['slug']) ?>.levelupgrowth.io/" target="_blank" rel="noopener">Open the real site <?= icon('arrow-right', 13) ?></a></figcaption>
      </figure>
    </div>
  </div>
  <div class="sim-bar">
    <button class="btn btn-secondary sim-play" type="button" data-sim-play hidden><?= icon('zap', 16) ?> Replay it</button>
    <span class="sim-note">A real conversation, replayed. The site it made is live.</span>
  </div>
</div>

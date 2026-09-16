<?php
/**
 * The article, as it actually happened.
 *
 * Left: a real conversation with Sarah in a real workspace — you ask, she answers, she delegates to Priya.
 * Right: what came out of it — the real title, the real meta description, the real length, and a link to the
 * published page. Provenance and the one marked elision are documented in journey-data.php. Without JavaScript
 * the whole thing is simply visible.
 *
 * @var array $j journey-data.php
 */
$a = $j['article'];
?>
<div class="sim2 sim" data-sim>
  <div class="sim2-grid">
    <div class="sim2-one">
      <div class="sim2-head"><span class="sim-dot"></span>Your workspace · a real conversation</div>
      <?php foreach ($a['messages'] as $i => $m): ?>
      <div class="sim-msg sim-<?= e($m['who']) ?>" data-sim-step="<?= $i ?>">
        <?php if ($m['who'] !== 'you'): ?><span class="sim-who"><?= e($m['name']) ?></span><?php endif; ?>
        <p><?= e($m['text']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="sim-result" data-sim-result>
      <div class="sim2-out">
        <h4><?= e($a['title']) ?></h4>
        <ul class="sim2-meta">
          <li><b>Meta</b><span><?= e($a['meta']) ?></span></li>
          <li><b>Keyword</b><span><?= e($a['keyword']) ?></span></li>
          <li><b>Length</b><span><?= (int) $a['words'] ?> words</span></li>
          <li><b>Written by</b><span>Priya, content manager</span></li>
          <li><b>State</b><span><?= e($a['state']) ?></span></li>
        </ul>
        <?php if (! empty($a['url'])): ?>
        <p class="mt"><a class="in in-light" href="<?= e($a['url']) ?>" target="_blank" rel="noopener">Read it on the site <?= icon('arrow-right', 14) ?></a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="sim2-foot">
    <button class="btn btn-secondary sim-play" type="button" data-sim-play hidden><?= icon('zap', 16) ?> Replay it</button>
    <span class="sim2-note"><?= e($a['note']) ?></span>
  </div>
</div>

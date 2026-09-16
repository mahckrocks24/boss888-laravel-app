<?php
/**
 * A real visitor conversation with a real chatbot on a real site.
 *
 * The messages are captured verbatim from a session against the showcase travel agency's own chatbot, which was
 * taught nothing except that site's pages. The lead panel on the right is what the business sees afterwards.
 *
 * @var array $j journey-data.php
 */
$c = $j['chat'];
?>
<div class="sim2 sim" data-sim>
  <div class="sim2-grid">
    <div class="sim2-one">
      <div class="sim2-head"><span class="sim-dot"></span><?= e($c['site']) ?> · 11:04pm</div>
      <?php foreach ($c['messages'] as $i => $m): ?>
      <div class="sim-msg sim-<?= e($m['who']) ?>" data-sim-step="<?= $i ?>">
        <?php if ($m['who'] !== 'visitor'): ?><span class="sim-who"><?= e($m['name']) ?></span><?php endif; ?>
        <p><?= e($m['text']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="sim-result" data-sim-result>
      <div class="sim2-out">
        <h4>What the business woke up to</h4>
        <ul class="sim2-meta">
          <?php foreach ($c['lead'] as $k => $v): ?>
          <li><b><?= e($k) ?></b><span><?= e($v) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="sim2-lead">
          <b>In the CRM</b>
          <span><?= e($c['crm']) ?></span>
        </div>
      </div>
    </div>
  </div>
  <div class="sim2-foot">
    <button class="btn btn-secondary sim-play" type="button" data-sim-play hidden><?= icon('zap', 16) ?> Replay it</button>
    <span class="sim2-note"><?= e($c['note']) ?></span>
  </div>
</div>

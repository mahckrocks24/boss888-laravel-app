<?php
/**
 * The loop, in one screen: you ask, Sarah plans and prices, a specialist produces, you approve, it publishes.
 *
 * Every value is from the run recorded in journey-data.php — the question that was typed, the specialist Sarah
 * actually assigned, the credit she actually quoted, the length of the article that came back, the score it got.
 * This is the same evidence the long transcript carried, compressed to the shape of the story.
 *
 * @var array $j journey-data.php
 */
$a = $j['article'];
$steps = [
    ['n' => '01', 'who' => 'You',    'label' => 'Ask',
     'text' => 'People keep asking us when to go to Japan for the cherry blossom. Can we put something on the site about it?'],
    ['n' => '02', 'who' => 'Sarah',  'label' => 'Plans and prices it',
     'text' => 'Queued as an article. Priya is writing it. 1 credit.'],
    ['n' => '03', 'who' => 'Priya',  'label' => 'Produces it',
     'text' => e($a['words']) . ' words, a meta description, a featured image, and answer-engine markup.'],
    ['n' => '04', 'who' => 'You',    'label' => 'Approve',
     'text' => 'It waits in one queue until you say yes. Nothing publishes without that.'],
    ['n' => '05', 'who' => 'Live',   'label' => 'Published',
     'text' => 'On the site, scoring ' . (int) ($j['aeo']['score'] ?? 90) . '/100 with answer engines.'],
];
?>
<ol class="loop" aria-label="How a piece of work moves through the platform">
  <?php foreach ($steps as $i => $s): ?>
  <li class="loop-step loop-<?= strtolower($s['who']) ?>">
    <span class="loop-n"><?= e($s['n']) ?></span>
    <span class="loop-who"><?= e($s['who']) ?></span>
    <b class="loop-label"><?= e($s['label']) ?></b>
    <span class="loop-text"><?= $s['text'] ?></span>
  </li>
  <?php endforeach; ?>
</ol>
<?php if (! empty($a['url'])): ?>
<p class="loop-out"><a class="in" href="<?= e($a['url']) ?>" target="_blank" rel="noopener">Read what came out <?= icon('arrow-right', 15) ?></a></p>
<?php endif; ?>

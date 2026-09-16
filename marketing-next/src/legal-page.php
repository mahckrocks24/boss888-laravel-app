<?php
/** @var array $page */ /** @var array $data */ /** @var array $l */
$page['title'] = $l['title'];
$page['description'] = $l['title'] . ' for LevelUpGrowth.';
$hasOwner = str_contains($l['body'], 'class="owner"');
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/">Home</a><span>/</span><span>Legal</span></nav>
    <h1><?= e($l['title']) ?></h1>
    <?php if ($hasOwner): ?><p class="notice">This policy is published in its pre-launch form. Company details shown as <mark class="owner">to be confirmed before launch</mark> will be completed before public launch; nothing on this page is invented.</p><?php endif; ?>
  </div>
</section>
<section class="section-tight">
  <div class="container narrow prose legal"><?= $l['body'] ?></div>
  <div class="container narrow mt"><p class="fine">Other policies: <a href="/next/legal/terms/">Terms</a> · <a href="/next/legal/privacy/">Privacy</a> · <a href="/next/legal/cookies/">Cookies</a> · <a href="/next/legal/refunds/">Refunds</a> · <a href="/next/legal/ai-disclosure/">AI disclosure</a></p></div>
</section>

<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Customers';
$page['description'] = 'Customer stories appear here when they are real: named customers, their own numbers, their consent.';
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Customers</p>
    <h1>Customer stories, when they are real.</h1>
    <p class="lede">This page holds named customers, measured from their own data with the starting point shown, with their consent. It fills as those stories exist; it will never carry an anonymous "a client of ours" or a number we made up.</p>
    <ul class="feature-list">
      <li><?= icon('check', 18) ?><span>Named, consenting customers</span></li>
      <li><?= icon('check', 18) ?><span>Numbers from their own workspace, before and after</span></li>
      <li><?= icon('check', 18) ?><span>Dated, and updated when the numbers change</span></li>
    </ul>
    <p class="mt">Until then, the proof is the product: open a <a href="/next/solutions/">live site built for your industry</a>, read <a href="/next/security/">how we handle data</a>, or <a href="<?= e(signup_href($data)) ?>">start free</a>.</p>
  </div>
</section>

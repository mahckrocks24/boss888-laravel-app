<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'About';
$page['description'] = 'LevelUpGrowth builds the business operating system you own and the AI workforce that runs it, approval first.';
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">About</p>
    <h1>We build the business you own, and the workforce that runs it.</h1>
    <p class="lede">Small businesses were sold a dozen subscriptions and told to stitch them together. We built one account that holds the assets a business runs on, and a workforce of named specialists that does the work and asks before anything goes live.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container narrow prose">
    <h2>What we believe</h2>
    <p><strong>Ownership is the product.</strong> Your site is exportable, your data is exportable, and when domains and mailboxes are yours they are registered to you. A platform that holds your assets hostage is renting them to you.</p>
    <p><strong>Approval is the safety.</strong> An AI that publishes on its own is a liability. Ours proposes, a manager reviews, and you decide. The review is real: work that drifts off brief, lacks metadata or cannot show its source is sent back and never counted.</p>
    <p><strong>Honesty is a feature.</strong> Our prices are read from the same table the platform enforces. Our comparison figures carry sources and dates. Our customers page stays empty until a named customer with their own numbers agrees to be on it.</p>
    <h2>How the company works</h2>
    <p>The platform is run by a small team and by the same workforce we sell: Sarah plans our own marketing, <?= $specialists ?> specialists produce it, and every post on our blog passes the same review gate our customers use. We eat what we cook.</p>
    <h2>Company details</h2>
    <p>Company registration details will be published here before public launch. They are printed only once confirmed by the company, never before.</p>
    <h2>Contact</h2>
    <p><a href="/next/contact/">Write to us</a>, or email hello@levelupgrowth.io.</p>
  </div>
</section>

<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Contact';
$page['description'] = 'Write to LevelUpGrowth about enterprise plans, support, press or partnerships. Messages land in our own CRM and get a reply from a person.';
$topics = ['enterprise' => 'Enterprise and Agency+', 'support' => 'Support', 'press' => 'Press', 'partnership' => 'Partnership', 'other' => 'Something else'];
?>
<section class="section-tight page-head">
  <div class="container grid-2 align-start">
    <div>
      <p class="eyebrow">Contact</p>
      <h1>Talk to a person.</h1>
      <p class="lede">Messages go into our own CRM, the same one you would use, and a person replies. For enterprise and agency plans, tell us how many sites and what you need invoiced.</p>
      <ul class="feature-list">
        <li><?= icon('check', 18) ?><span>Email: hello@levelupgrowth.io</span></li>
        <li><?= icon('check', 18) ?><span>Security concerns: the same address, marked "security"</span></li>
        <li><?= icon('check', 18) ?><span>Live checks: <a href="/next/status/">status page</a></span></li>
      </ul>
    </div>
    <form class="form card" id="contact" data-api="/api/public/contact" method="post" action="/api/public/contact" novalidate>
      <div class="form-row">
        <label for="ct-topic">Topic</label>
        <div class="select"><select id="ct-topic" name="topic" required><?php foreach ($topics as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select><?= icon('arrow-right', 16, 'select-icon') ?></div>
      </div>
      <div class="form-row"><label for="ct-name">Your name</label><input id="ct-name" name="name" type="text" autocomplete="name" required minlength="2" maxlength="120"></div>
      <div class="form-row"><label for="ct-email">Email</label><input id="ct-email" name="email" type="email" autocomplete="email" required maxlength="190"></div>
      <div class="form-row"><label for="ct-company">Company <span class="opt">optional</span></label><input id="ct-company" name="company" type="text" autocomplete="organization" maxlength="150"></div>
      <div class="form-row"><label for="ct-message">Message</label><textarea id="ct-message" name="message" rows="5" required minlength="10" maxlength="4000"></textarea></div>
      <label class="check"><input type="checkbox" name="consent" value="1" required><span class="check-box" aria-hidden="true"><?= icon('check', 14) ?></span><span>You may reply to me by email. <a href="/next/legal/privacy/">Privacy</a></span></label>
      <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></label></div>
      <input type="hidden" name="source_page" value="/next/contact/">
      <p class="form-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-lg" type="submit">Send <?= icon('arrow-right', 18) ?></button>
      <div class="form-success" hidden><?= icon('check', 28) ?><h3>Received.</h3><p>Reference <strong class="ref"></strong>. A person will reply by email.</p></div>
    </form>
  </div>
</section>

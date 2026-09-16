<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Reset password';
$page['description'] = 'Choose a new password for your LevelUpGrowth account.';
$page['noindex'] = true;
?>
<section class="section-tight page-head">
  <div class="container grid-2 align-start">
    <div>
      <p class="eyebrow">Reset password</p>
      <h1>Choose a new password.</h1>
      <p class="lede">The link in your email brought you here. Pick a new password — eight characters or more — and you are signed in on the next screen.</p>
    </div>
    <form class="form card" id="reset" method="post" action="/api/auth/reset-password" novalidate>
      <div class="form-row">
        <label for="rp-pass">New password</label>
        <div class="pw-wrap">
          <input id="rp-pass" name="password" type="password" autocomplete="new-password" required minlength="8">
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password">Show</button>
        </div>
      </div>
      <div class="form-row">
        <label for="rp-pass2">Repeat it</label>
        <input id="rp-pass2" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8">
      </div>
      <p class="form-error" id="rp-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-lg" type="submit" id="rp-submit">Set new password <?= icon('arrow-right', 18) ?></button>
      <p class="fine form-fine form-links"><a href="/next/login/">Back to sign in</a></p>
      <div class="form-success" id="rp-success" hidden>
        <?= icon('check', 28) ?>
        <h3>Password changed.</h3>
        <p><a href="/next/login/">Sign in with it now</a>.</p>
      </div>
    </form>
  </div>
</section>

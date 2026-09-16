<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Log in';
$page['description'] = 'Sign in to your LevelUpGrowth workspace.';
$page['noindex'] = true;
$page['chrome'] = 'minimal';   // Owner 2026-09-15 (EV-1037): the login page is the form only — no pitch, no header links, a one-line footer
?>
<section class="section-tight page-head"><div class="container" style="max-width:460px"><h1 style="text-align:center;margin-bottom:18px">Log in</h1><form class="form card" id="login" method="post" action="/api/auth/login" novalidate data-mode="login">
      <div class="form-row">
        <label for="li-email">Email</label>
        <input id="li-email" name="email" type="email" autocomplete="email" required maxlength="190" inputmode="email">
      </div>
      <div class="form-row" data-login-only>
        <label for="li-pass">Password</label>
        <div class="pw-wrap">
          <input id="li-pass" name="password" type="password" autocomplete="current-password" required minlength="8">
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password">Show</button>
        </div>
      </div>
      <p class="form-error" id="li-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-lg" type="submit" id="li-submit" style="justify-content:center;text-align:center;width:100%">Sign in <?= icon('arrow-right', 18) ?></button>
      <p class="fine form-fine form-links">
        <a href="#" data-forgot data-login-only>Forgot password?</a>
        <a href="#" data-back-to-login hidden>Back to sign in</a>
        <span class="sep" aria-hidden="true">·</span>
        <span>No account? <a href="<?= e(signup_href($data)) ?>">Create one free</a></span>
      </p>
      <div class="form-success" id="li-success" hidden>
        <?= icon('check', 28) ?>        <h3>Check your inbox.</h3>
        <p>If that address has an account, a reset link is on its way. It works for one hour.</p>
      </div>
    </form>
  </div>
</section>

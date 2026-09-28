{{-- MAIL-BRAND-1: password reset on the one branded design --}}
@php
  $__o = [
    'preheader'  => 'Choose a new password for your LevelUpGrowth account.',
    'heading'    => 'Reset your password',
    'greeting'   => 'Hi' . (isset($user->name) && $user->name ? ' ' . trim(explode(' ', (string) $user->name)[0]) : '') . ',',
    'paragraphs' => ['Tap the button to choose a new password. The link works for ' . (int) $expireMin . ' minutes.'],
    'button'     => ['Reset my password', (string) $resetUrl],
    'after'      => [
      'If the button does not open, copy this address into your browser: <span style="word-break:break-all">' . e((string) $resetUrl) . '</span>',
      "Didn't ask for this? You can ignore this email; your password stays the same.",
    ],
    'reason'     => 'You received this email because a password reset was requested for your LevelUpGrowth account.',
  ];
@endphp
{!! \App\Core\Lifecycle\EmailLayout::render($__o) !!}

{{-- MAIL-BRAND-1: every notification email on the one branded design; the button opens the exact item inside /app (MAIL-AUDIT-1) --}}
@php
  $__to = (string) $notification->action_url;
  if ($__to !== '' && ! preg_match('#^(https?:)?//#', $__to) && ! str_starts_with($__to, '/app')) $__to = '/app' . (str_starts_with($__to, '/') ? '' : '/') . $__to;
  $__base = \App\Core\Lifecycle\EmailLayout::appUrl();
  $__o = [
    'preheader'   => (string) ($notification->body ?? $notification->title),
    'hero' => 'alert', 'eyebrow' => 'Notification',
    'heading'     => (string) $notification->title,
    'paragraphs'  => $notification->body ? [nl2br(e((string) $notification->body))] : [],
    'button'      => $__to !== '' ? ['Open in LevelUpGrowth', preg_match('#^(https?:)?//#', $__to) ? $__to : $__base . $__to] : null,
    'reason'      => 'You received this email because notifications are on for your LevelUpGrowth account.',
    'preferences' => $__base . '/app/settings',
  ];
@endphp
{!! \App\Core\Lifecycle\EmailLayout::render($__o) !!}

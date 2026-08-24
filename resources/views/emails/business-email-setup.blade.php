{{--
  INFRA888 · E7.3 — LevelUp Growth mailbox onboarding email.

  LEVELUP BRANDING ONLY. The provider is never named, never linked, and never
  implied. This message replaces the provider's own invitation, which named the
  vendor in its sender, its subject, its link and its footer.

  It contains NO password. It contains a single-use link that expires.
--}}
<div style="font:400 15px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1F2430;max-width:520px;">
  <p style="font:600 16px/1 inherit;color:#111827;margin:0 0 24px;">LevelUp<span style="color:#6366F1;">Growth</span></p>

  <p style="margin:0 0 16px;">Hello,</p>

  <p style="margin:0 0 16px;">
    Your business email address <strong>{{ $address }}</strong> is ready.
    Choose a password to finish setting it up.
  </p>

  <p style="margin:0 0 28px;">
    <a href="{{ $setupUrl }}"
       style="display:inline-block;background:#6366F1;color:#ffffff;text-decoration:none;
              padding:12px 22px;border-radius:8px;font-weight:600;">Set your password</a>
  </p>

  <p style="margin:0 0 16px;color:#6B7280;font-size:13px;">
    This link works once and expires in {{ $expiresHours }} hours.
    If it has expired, ask your administrator to send a new one.
  </p>

  <p style="margin:0 0 16px;color:#6B7280;font-size:13px;">
    If you weren't expecting this, you can ignore this message — nothing will change.
  </p>

  <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #E5E7EB;color:#6B7280;font-size:12px;">
    Business Email by LevelUp Growth.<br>
    Need help? Reply to this message or contact LevelUp Growth support.
  </p>
</div>
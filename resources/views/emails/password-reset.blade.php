<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset your LevelUp Growth password</title>
</head>
<body style="margin:0;padding:0;background:#F5F7FB;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F1117;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FB;padding:40px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:12px;box-shadow:0 2px 8px rgba(15,17,23,0.06);overflow:hidden;">
<tr><td style="padding:32px 40px 16px 40px;border-bottom:1px solid #E8EDF5;">
<div style="font-size:20px;font-weight:700;background:linear-gradient(135deg,#6C5CE7,#00E5A8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;color:#6C5CE7;">LevelUp Growth</div>
</td></tr>
<tr><td style="padding:32px 40px;">
<h1 style="margin:0 0 16px 0;font-size:22px;font-weight:600;color:#0F1117;">Reset your password</h1>
<p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#4A566B;">Hi{{ isset($user->name) && $user->name ? ' ' . $user->name : '' }},</p>
<p style="margin:0 0 24px 0;font-size:14px;line-height:1.6;color:#4A566B;">Click the button below to reset your password. This link expires in {{ $expireMin }} minutes.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;">
<tr><td style="background:#6C5CE7;border-radius:8px;">
<a href="{{ $resetUrl }}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#FFFFFF;text-decoration:none;">Reset Password</a>
</td></tr>
</table>
<p style="margin:0 0 8px 0;font-size:12px;line-height:1.6;color:#8B97B0;">Or copy and paste this URL into your browser:</p>
<p style="margin:0 0 24px 0;font-size:12px;line-height:1.6;color:#6C5CE7;word-break:break-all;">{{ $resetUrl }}</p>
<p style="margin:0;font-size:13px;line-height:1.6;color:#8B97B0;">If you didn't request a password reset, you can safely ignore this email — your password won't change.</p>
</td></tr>
<tr><td style="padding:20px 40px 28px 40px;border-top:1px solid #E8EDF5;background:#FAFBFD;">
<p style="margin:0;font-size:12px;line-height:1.5;color:#8B97B0;">LevelUp Growth · levelupgrowth.io</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>

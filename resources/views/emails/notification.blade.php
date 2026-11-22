<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $title }}</title></head>
<body style="margin:0;padding:0;background:#f5f4f0;font-family:Georgia,'Times New Roman',serif;color:#1c2321;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f0;padding:32px 0;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e3e0d8;border-radius:8px;">
<tr><td style="padding:28px 32px 8px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#6b6f6a;">McNutt Legacy{{ $workspace ? ' · '.$workspace : '' }}</td></tr>
<tr><td style="padding:4px 32px 8px;font-size:22px;line-height:1.3;color:#1c2321;">{{ $title }}</td></tr>
@if($body)<tr><td style="padding:8px 32px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#3d4440;">{{ $body }}</td></tr>@endif
<tr><td style="padding:20px 32px 28px;"><a href="{{ $link }}" style="display:inline-block;background:#1f3a33;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px;padding:12px 22px;border-radius:6px;">Open McNutt Legacy</a></td></tr>
<tr><td style="padding:16px 32px 24px;border-top:1px solid #eeeae2;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#8a8f8a;">Details are available after you sign in. You can change email preferences in Settings.</td></tr>
</table>
</td></tr>
</table>
</body>
</html>

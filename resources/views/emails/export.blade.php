<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Documents shared with you</title></head>
<body style="margin:0;padding:0;background:#f5f4f0;font-family:Georgia,'Times New Roman',serif;color:#1c2321;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f0;padding:32px 0;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e3e0d8;border-radius:8px;">
<tr><td style="padding:28px 32px 8px;font-size:22px;">Hello {{ $name }},</td></tr>
<tr><td style="padding:8px 32px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#3d4440;">
{{ $from }} shared a document package with you through McNutt Legacy. You will need the access code they give you separately. The link works until {{ \Illuminate\Support\Carbon::parse($expires)->toFormattedDateString() }}.
</td></tr>
<tr><td style="padding:20px 32px 28px;"><a href="{{ $link }}" style="display:inline-block;background:#1f3a33;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px;padding:12px 22px;border-radius:6px;">View shared package</a></td></tr>
</table></td></tr></table>
</body>
</html>

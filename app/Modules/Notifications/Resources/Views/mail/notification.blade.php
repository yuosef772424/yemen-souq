<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:24px; background:#f7f7f5; font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; color:#1a1a1a;">
    <table role="presentation" width="100%" style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:16px; border:1px solid #e5e5e0;">
        <tr>
            <td style="padding:24px;">
                <h1 style="margin:0 0 12px; font-size:18px;">{{ $title }}</h1>

                @if ($body)
                    <p style="margin:0 0 20px; line-height:1.7; font-size:14px; color:#333;">{{ $body }}</p>
                @endif

                @if ($url)
                    <a href="{{ $url }}"
                       style="display:inline-block; background:#0a6b46; color:#ffffff; text-decoration:none; padding:10px 18px; border-radius:10px; font-size:14px;">
                        فتح في {{ config('app.name') }}
                    </a>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>

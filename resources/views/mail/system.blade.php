<!doctype html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f1ec;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1c2333;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f1ec;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6e1d6;">
    <tr>
        <td style="background:#101828;padding:22px 28px;">
            <table role="presentation" width="100%"><tr>
                <td style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:.2px;">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#2dd4bf;margin-right:8px;"></span>TradeFlow
                </td>
                @if($companyName)
                    <td align="right" style="font-size:13px;color:#98a2b3;">{{ $companyName }}</td>
                @endif
            </tr></table>
        </td>
    </tr>
    <tr>
        <td style="padding:28px 28px 8px;">
            <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;color:#101828;">{{ $heading }}</h1>
            @foreach($lines as $line)
                <p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#344054;">{{ $line }}</p>
            @endforeach
        </td>
    </tr>
    @foreach($sections as $section)
        @if(count($section['items'] ?? []))
        <tr>
            <td style="padding:8px 28px 4px;">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#0f766e;margin:10px 0 8px;">
                    {{ $section['title'] }} ({{ count($section['items']) }})
                </div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    @foreach($section['items'] as $item)
                        <tr>
                            <td style="padding:10px 12px;border:1px solid #eef0f3;border-left:3px solid {{ ($item['tone'] ?? null) === 'danger' ? '#e11d48' : '#14b8a6' }};background:#fcfcfd;">
                                <div style="font-size:14px;font-weight:600;color:#101828;">
                                    @if(!empty($item['url']))
                                        <a href="{{ $item['url'] }}" style="color:#101828;text-decoration:none;">{{ $item['title'] }}</a>
                                    @else
                                        {{ $item['title'] }}
                                    @endif
                                </div>
                                @if(!empty($item['meta']))
                                    <div style="font-size:13px;color:{{ ($item['tone'] ?? null) === 'danger' ? '#be123c' : '#667085' }};margin-top:3px;">{{ $item['meta'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
        @endif
    @endforeach
    @if($actionText && $actionUrl)
        <tr>
            <td style="padding:22px 28px 6px;">
                <a href="{{ $actionUrl }}" style="display:inline-block;background:#0f9d8a;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:9px;">{{ $actionText }}</a>
            </td>
        </tr>
    @endif
    <tr>
        <td style="padding:22px 28px 26px;">
            <p style="margin:0;font-size:12px;line-height:1.6;color:#98a2b3;">
                {{ $footnote ?? 'Bu məktub TradeFlow tərəfindən avtomatik göndərilib.' }}
                Bildiriş ayarlarını profilinizdən dəyişə bilərsiniz.
            </p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>

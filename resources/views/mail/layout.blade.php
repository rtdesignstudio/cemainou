{{-- Emailurile cemainou: alb, text aproape negru, o linie sub nume, un singur buton negru; stiluri inline (clienții de email ignoră <style>). --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'cemainou')</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;font-family:'Inter Tight','Helvetica Neue',Helvetica,Arial,sans-serif;color:#0a0a0a;">
                <tr>
                    <td style="padding-bottom:24px;border-bottom:1px solid #0a0a0a;font-size:20px;font-weight:600;letter-spacing:-0.04em;">cemainou</td>
                </tr>
                <tr>
                    <td style="padding:32px 0 8px;font-size:28px;line-height:1.15;font-weight:500;letter-spacing:-0.03em;">@yield('heading')</td>
                </tr>
                <tr>
                    <td style="padding:16px 0 32px;font-size:16px;line-height:1.6;">@yield('content')</td>
                </tr>
                <tr>
                    <td style="padding-top:16px;border-top:1px solid #e5e5e5;font-size:12px;line-height:1.5;color:#737373;">@yield('footer', config('app.url'))</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

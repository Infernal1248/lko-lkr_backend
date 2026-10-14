<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Восстановление пароля — ЛКО / ЛКР</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;color:#26374a;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;color:#f4f6f8;max-height:0;max-width:0;opacity:0;overflow:hidden;">Ссылка для смены пароля в личном кабинете ЛКО / ЛКР. Срок действия — {{ $minutes }} мин.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;">
<tr><td align="center" style="padding:32px 16px;">
    <!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
        <tr><td style="padding:24px;background-color:#172d43;border-radius:10px 10px 0 0;">
            <a href="{{ $homeUrl }}" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:bold;letter-spacing:1px;">ЛКО / ЛКР</a>
            <p style="margin:8px 0 0;color:#c1cddb;font-size:13px;line-height:20px;">Личный кабинет</p>
        </td></tr>
        <tr><td style="padding:28px 24px;background-color:#ffffff;border:1px solid #e3e8ed;border-top:0;border-radius:0 0 10px 10px;">
            <h1 style="margin:0 0 24px;font-size:24px;line-height:32px;color:#172d43;">Восстановление пароля</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:24px;">Здравствуйте!</p>
            <p style="margin:0 0 24px;font-size:15px;line-height:24px;">Мы получили запрос на смену пароля для вашего аккаунта. Нажмите кнопку ниже, чтобы задать новый пароль.</p>
            @if($username)<p style="margin:0 0 24px;font-size:15px;line-height:24px;">Логин аккаунта: <strong>{{ $username }}</strong></p>@endif
            <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#172d43" style="border-radius:6px;text-align:center;mso-padding-alt:14px 24px;">
                <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 24px;border:1px solid #172d43;border-radius:6px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;line-height:20px;">Задать новый пароль</a>
            </td></tr></table>
            <p style="margin:16px 0 24px;font-size:13px;line-height:20px;color:#5f7081;">Срок действия ссылки — {{ $minutes }} мин. После смены пароля воспользоваться ею повторно нельзя.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:16px;background-color:#f4f6f8;border-radius:6px;font-size:14px;line-height:22px;">
                <strong>Не запрашивали смену пароля?</strong><br>Просто проигнорируйте это письмо. Ваш пароль останется прежним.
            </td></tr></table>
            <p style="margin:24px 0 8px;padding-top:20px;border-top:1px solid #e3e8ed;color:#5f7081;font-size:12px;line-height:19px;">Если кнопка не работает, скопируйте ссылку и вставьте её в адресную строку браузера:</p>
            <p style="margin:0;font-size:12px;line-height:19px;word-break:break-all;overflow-wrap:anywhere;"><a href="{{ $resetUrl }}" style="color:#365b7b;text-decoration:underline;word-break:break-all;">{{ $resetUrl }}</a></p>
        </td></tr>
        <tr><td align="center" style="padding:20px 12px;color:#788493;font-size:12px;line-height:20px;">ЛКО / ЛКР · <a href="{{ $homeUrl }}" style="color:#5f7081;text-decoration:none;">лко-лкр.рф</a><br>Это письмо отправлено автоматически.</td></tr>
    </table>
    <!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>

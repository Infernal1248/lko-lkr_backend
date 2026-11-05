<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Код регистрации — ЛКО / ЛКР</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;color:#26374a;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;color:#f4f6f8;max-height:0;max-width:0;opacity:0;overflow:hidden;">Код для подтверждения почты при регистрации.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;">
<tr><td align="center" style="padding:32px 16px;">
    <!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
        <tr><td style="padding:24px;background-color:#172d43;border-radius:10px 10px 0 0;">
            <a href="{{ $homeUrl }}" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:bold;letter-spacing:1px;">ЛКО / ЛКР</a>
            <p style="margin:8px 0 0;color:#c1cddb;font-size:13px;line-height:20px;">Личный кабинет</p>
        </td></tr>
        <tr><td style="padding:28px 24px;background-color:#ffffff;border:1px solid #e3e8ed;border-top:0;border-radius:0 0 10px 10px;">
            <h1 style="margin:0 0 24px;font-size:24px;line-height:32px;color:#172d43;">Код регистрации</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:24px;">Здравствуйте!</p>
            <p style="font-size:15px;line-height:24px;">Введите этот код в форме регистрации, чтобы подтвердить почту и создать аккаунт:</p>
            <p style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#172d43;">{{ $code }}</p>
            <p style="font-size:14px;line-height:22px;">Код действует 10 минут. Никому не сообщайте его.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:16px;background-color:#f4f6f8;border-radius:6px;font-size:14px;line-height:22px;">
                <strong>Не запрашивали подтверждение?</strong><br>Просто проигнорируйте это письмо.
            </td></tr></table>
        </td></tr>
        <tr><td align="center" style="padding:20px 12px;color:#788493;font-size:12px;line-height:20px;">ЛКО / ЛКР · <a href="{{ $homeUrl }}" style="color:#5f7081;text-decoration:none;">лко-лкр.рф</a><br>Это письмо отправлено автоматически.</td></tr>
    </table>
    <!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>

<?php

return [
    'captcha_enabled' => env('SMARTCAPTCHA_ENABLED', true),
    'site_key' => env('SMARTCAPTCHA_SITE_KEY', ''),
    'secret_key' => env('SMARTCAPTCHA_SECRET_KEY', ''),
    'host' => env('SMARTCAPTCHA_HOST', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
];

<?php

return [
    // Same origin as the cabinet. Never derive canonical URLs from the request Host.
    'url' => env('SITE_URL', env('APP_URL', 'https://xn----stbbbcqr.xn--p1ai')),
];

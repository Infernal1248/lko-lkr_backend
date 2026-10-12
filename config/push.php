<?php

return [
    'enabled' => (bool) env('PUSH_ENABLED', false),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT', 'mailto:kochergin.vladim@mail.ru'),
];

<?php

return [
    'secret' => env('NPD_BOT_SECRET', ''),
    // Fixed rollout boundary, never evaluated as "now" on each request.
    'since' => env('NPD_BOT_SINCE', '2026-10-13T17:36:24+07:00'),
];

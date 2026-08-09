<?php

return [
    'phone_verification' => [
        'driver' => env('PHONE_VERIFICATION_DRIVER', 'display'),
        'code_length' => env('PHONE_VERIFICATION_CODE_LENGTH', 6),
        'ttl_minutes' => env('PHONE_VERIFICATION_TTL_MINUTES', 10),
        'max_attempts' => env('PHONE_VERIFICATION_MAX_ATTEMPTS', 5),
        'max_sends_per_window' => env('PHONE_VERIFICATION_MAX_SENDS', 3),
        'send_window_minutes' => env('PHONE_VERIFICATION_SEND_WINDOW', 5),
    ],

    'require_phone_verification_to_publish' => env('REQUIRE_PHONE_VERIFICATION', true),
];
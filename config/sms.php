<?php

return [
    'default' => env('SMS_DRIVER', 'bulksmsbd'),

    'enabled' => env('SMS_ENABLED', true),

    'drivers' => [
        'bulksmsbd' => [
            'api_key' => env('BULKSMSBD_API_KEY', ''),
            'sender_id' => env('BULKSMSBD_SENDER_ID', ''),
            'base_url' => env('BULKSMSBD_BASE_URL', 'http://bulksmsbd.net/api/smsapi'),
            'balance_url' => env('BULKSMSBD_BALANCE_URL', 'http://bulksmsbd.net/api/getBalanceApi'),
        ],

        'log' => [],

        'mock' => [],
    ],

    'otp' => [
        'expiry_minutes' => (int) env('SMS_OTP_EXPIRY_MINUTES', 5),
        'length' => (int) env('SMS_OTP_LENGTH', 6),
        'cooldown_seconds' => (int) env('SMS_OTP_COOLDOWN_SECONDS', 60),
        'max_attempts' => (int) env('SMS_OTP_MAX_ATTEMPTS', 5),
    ],
];

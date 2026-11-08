<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | The nightly `audit:prune` job removes routine audit entries older than
    | `retention_days`. Entries in `protected_modules` are kept forever: they
    | are the money, accounting, sign-in, seller billing and settings history
    | that may be needed for disputes, tax or a statutory audit (AUD-1). The
    | legal minimum retention period still needs to be confirmed.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),

    'protected_modules' => [
        'accounting',
        'approvals',
        'audit',
        'auth',
        'finance',
        'kyc',
        'monetization',
        'orders',
        'payment',
        'sellers',
        'settings',
    ],

];

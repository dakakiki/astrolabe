<?php

// The operator's admin (Phase 8c) and what astrologers hear about their account.
return [
    'only' => 'This is only for the operator.',
    'two_factor_required' => 'Turn on two-factor sign-in before using the admin.',
    'idle' => 'The admin session ended after a while without activity. Please sign in again.',
    'suspended' => 'This account is suspended. Please contact support.',
    'not_an_astrologer' => 'Admin accounts are managed from the command line.',
    'already_verified' => 'This email address is already confirmed.',
    'not_suspended' => 'This account is not suspended.',
    'already_suspended' => 'This account is already suspended.',
    'two_factor_off' => 'Two-factor sign-in is not on for this account.',
    'has_account' => 'This address already has an account.',
    'job_missing' => 'This failed job is no longer there.',

    'notice' => [
        'support' => 'Questions? Write to :email.',
        'reason' => 'Reason given: :reason',
        'two_factor_reset' => [
            'subject' => 'Two-factor sign-in was turned off',
            'line' => 'Support turned off two-factor sign-in for your AstroLabe account, as you asked. You can sign in with your password now.',
            'again' => 'Please turn it on again in Settings → Security, with a new code from your authenticator app.',
            'not_you' => 'If you did not ask for this, change your password and contact support at once.',
        ],
        'suspended' => [
            'subject' => 'Your AstroLabe account was suspended',
            'line' => 'Your account was suspended by support. You cannot sign in until it is restored; nothing in your practice was changed or deleted.',
        ],
        'restored' => [
            'subject' => 'Your AstroLabe account works again',
            'line' => 'Your account was restored. You can sign in as before.',
            'action' => 'Sign in',
        ],
    ],

    'feedback' => [
        'subject' => 'New feedback in :app',
        'line' => 'A :category came in from astrologer #:user. Read it in the admin under Feedback.',
    ],

    'create' => [
        'subject' => 'Your AstroLabe admin account',
        'line' => 'An admin account was made for this address. Set its password with the button below; the link works for :minutes minutes.',
        'two_factor' => 'After signing in, turn on two-factor sign-in — the admin opens only with it.',
        'action' => 'Set the password',
    ],
];

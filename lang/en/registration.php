<?php

// Registration: open by default, or by invitation only ("invite" mode, Phase 8a).
return [
    'invitation' => [
        'required' => 'Accounts are by invitation for now: please use the link from your invitation email.',
        'invalid' => 'This invitation link is not valid. Please use the link from your invitation email.',
        'expired' => 'This invitation has expired. Ask for a new one.',
        'used' => 'This invitation has already been used. Sign in instead.',
        'revoked' => 'This invitation is no longer valid. Ask for a new one.',
        'other_email' => 'This invitation is for a different email address.',
    ],

    'too_many' => 'Too many new accounts from this connection. Please try again in :minutes minutes.',

    'mail' => [
        'subject' => 'Your invitation to :app',
        'intro' => 'You are invited to try :app, practice management for astrologers.',
        'expires' => 'The link works once and until :date.',
        'action' => 'Create your account',
        'ignore' => 'If you did not expect this invitation, you can ignore this email.',
    ],
];

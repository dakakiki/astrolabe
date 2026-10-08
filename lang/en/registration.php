<?php

// Closed beta: accounts are created through an invitation (Phase 8a).
return [
    'invitation' => [
        'required' => 'AstroLabe is in a closed beta: an account needs an invitation link.',
        'invalid' => 'This invitation link is not valid. Please use the link from your invitation email.',
        'expired' => 'This invitation has expired. Ask for a new one.',
        'used' => 'This invitation has already been used. Sign in instead.',
        'revoked' => 'This invitation is no longer valid. Ask for a new one.',
        'other_email' => 'This invitation is for a different email address.',
    ],

    'mail' => [
        'subject' => 'Your invitation to the :app beta',
        'intro' => 'You are invited to try :app, practice management for astrologers, during its closed beta.',
        'expires' => 'The link works once and until :date.',
        'action' => 'Create your account',
        'ignore' => 'If you did not expect this invitation, you can ignore this email.',
    ],
];

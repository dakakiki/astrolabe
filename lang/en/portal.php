<?php

// The client portal (Phase 9a, docs/spec/12): server-side text — emails, errors, validation.
return [
    'title' => 'Client portal',

    'errors' => [
        'unauthenticated' => 'Please sign in to your client portal.',
        'portal_no_access' => 'Your portal access is not open at the moment. Please contact your astrologer.',
        'portal_choose_practice' => 'Choose which practice to open.',
        'too_many' => 'Too many attempts. Please wait a few minutes and try again.',
    ],

    'invitation' => [
        'not_found' => 'This invitation link is not valid. Please use the link from your invitation email.',
        'not_valid' => 'This invitation can no longer be used.',
    ],

    'sign_in' => [
        'sent' => 'If this address has access to a client portal, we have sent a sign-in link and code to it.',
        'link_invalid' => 'This sign-in link has expired or was already used. Ask for a new one.',
        'code_invalid' => 'That code is not right or no longer works. Check the newest email, or ask for a new code.',
    ],

    'invite' => [
        'no_email' => 'Add the client\'s email address first — the invitation goes there.',
        'archived' => 'An archived client cannot be invited. Restore the client first.',
        'already_active' => 'This client already has portal access.',
    ],

    'branding' => [
        'logo_type' => 'The logo must be a PNG, WebP or SVG image.',
        'logo_unreadable' => 'This SVG could not be read. Export it again as a plain SVG, or use PNG.',
        'logo_shape' => 'The logo should be square or wide, not tall.',
    ],

    'mail' => [
        'invitation' => [
            'subject' => ':practice invited you to their client portal',
            'greeting' => 'Hello,',
            'intro' => ':name from :practice invited you to their client portal.',
            'what' => 'There you can see your appointments and what your astrologer shares with you. No password is needed: you sign in with a link or code sent to this address.',
            'action' => 'Open your portal',
            'expires' => 'The invitation works once and until :date.',
            'ignore' => 'If you did not expect this invitation, you can ignore this email.',
        ],
        'sign_in' => [
            'subject' => 'Sign in to your client portal',
            'intro' => 'Use this button to sign in to your client portal.',
            'action' => 'Sign in',
            'code' => 'Opening the portal on another device? Enter this code there: :code',
            'expires' => 'The link and the code work once, for :minutes minutes.',
            'ignore' => 'If you did not ask to sign in, you can ignore this email — nobody can sign in without it.',
        ],
    ],
];

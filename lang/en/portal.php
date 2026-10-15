<?php

// The client portal (Phase 9a, docs/spec/12): server-side text — emails, errors, validation.
return [
    'title' => 'Client portal',

    'errors' => [
        'unauthenticated' => 'Please sign in to your client portal.',
        'portal_no_access' => 'Your portal access is not open at the moment. Please contact your astrologer.',
        'portal_choose_practice' => 'Choose which practice to open.',
        'too_many' => 'Too many attempts. Please wait a few minutes and try again.',
        'push_unavailable' => 'Notifications on this device are not available at the moment.',
        'push_unsupported' => 'This browser\'s notifications are not supported. Please try another browser.',
        'push_not_on' => 'Notifications are not on for this device.',
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

        // About the client's appointments (Phase 9b): generic on purpose — the practice,
        // the time on the client's clock, the service and a link; never notes or messages.
        'booking' => [
            'greeting' => 'Hello :name,',
            'greeting_anonymous' => 'Hello,',
            'when' => ':day, :start – :end (:zone)',
            'service' => 'Service: :service',
            'where' => [
                'online' => 'Where: online',
                'in_person' => 'Where: in person',
            ],
            'was' => 'It was on :when.',
            'pending' => 'It still waits for :practice to confirm it. The time is held for you meanwhile.',
            'change_until' => 'You can move or cancel it in your portal until :when.',
            'move_until' => 'You can move it in your portal until :when.',
            'cancel_until' => 'You can cancel it in your portal until :when.',
            'action' => 'Open your appointments',
            'private' => 'Everything else about it is in your portal, after you sign in.',
            'booked' => [
                'subject' => 'Booked: your appointment on :when',
                'line' => 'Your appointment with :practice is booked for :when.',
            ],
            'requested' => [
                'subject' => 'Request received: your appointment on :when',
                'line' => ':practice received your request for an appointment on :when.',
                'next' => 'The time is held for you until :practice confirms it. You will get an email either way.',
            ],
            'confirmed' => [
                'subject' => 'Confirmed: your appointment on :when',
                'line' => ':practice confirmed your appointment on :when.',
            ],
            'declined' => [
                'subject' => 'Not accepted: your request for :when',
                'line' => ':practice could not accept your request for an appointment on :when.',
                'message' => 'Message from :practice: :message',
                'again' => 'You can choose another time in your portal, or contact :practice.',
            ],
            'moved' => [
                'subject' => 'Moved: your appointment is now on :when',
                'line' => ':practice moved your appointment to :when.',
            ],
            'moved_by_you' => [
                'subject' => 'Moved: your appointment is now on :when',
                'line' => 'As you asked, your appointment with :practice is now on :when.',
            ],
            'cancelled' => [
                'subject' => 'Cancelled: your appointment on :when',
                'line' => ':practice cancelled your appointment on :when.',
                'next' => 'If you have any questions about it, please contact :practice.',
            ],
            'cancelled_by_you' => [
                'subject' => 'Cancelled: your appointment on :when',
                'line' => 'As you asked, your appointment with :practice on :when is cancelled.',
                'next' => 'Thank you for letting :practice know.',
            ],
        ],
        'reminder' => [
            'subject' => 'Reminder: your appointment on :when',
            'line' => 'This is a reminder of your appointment with :practice on :when.',
            'off' => 'You get this reminder a day before each appointment. You can switch it off in your portal, under Profile.',
        ],
    ],

    // Web push in the client PWA (Phase 9c): generic on purpose — what happened and when,
    // never the practice, the service or a message. Shown on a lock screen.
    'push' => [
        'when' => [
            'today' => 'Today at :time',
            'tomorrow' => 'Tomorrow at :time',
            'day' => ':day at :time',
        ],
        'booked' => ['title' => 'Appointment booked'],
        'requested' => [
            'title' => 'Booking request sent',
            'body' => ':when · waiting for confirmation',
        ],
        'confirmed' => ['title' => 'Booking confirmed'],
        'declined' => [
            'title' => 'Booking not confirmed',
            'body' => ':when · open the portal for details',
        ],
        'moved' => ['title' => 'Appointment moved'],
        'moved_by_you' => ['title' => 'You moved your appointment'],
        'cancelled' => ['title' => 'Appointment cancelled'],
        'cancelled_by_you' => ['title' => 'You cancelled your appointment'],
        'reminder' => ['title' => 'Appointment reminder'],
        'test' => [
            'title' => 'Notifications are on',
            'body' => 'This device will get your appointment notices.',
        ],
    ],

    // The installable portal (Phase 9c): AstroLabe's app, the practice shows inside it.
    'manifest' => [
        'description' => 'Your appointments and what your astrologer shared with you.',
    ],

    // The page the PWA shows without a connection (Phase 9c). No data, no script of the app.
    'offline' => [
        'title' => 'You\'re offline',
        'line' => 'The portal needs a connection to show your appointments and what was shared with you.',
        'retry' => 'Try again',
    ],
];

<?php

// Booking in the client portal (Phase 9b): answers both SPAs show as they are.
// A client never learns why a time is not free (docs/spec/10, "Klijentski prikaz").

return [
    'slot_taken' => 'That time is no longer free. Please choose another one.',
    'change_too_late' => 'This appointment can no longer be changed here. Please contact the practice.',
    'not_changeable' => 'This appointment cannot be changed any more.',
    'disabled' => 'Booking in the portal is not open at the moment.',
    'not_requested' => 'Only a booking request waiting for confirmation can be confirmed or declined.',
    'too_many_open' => 'You already have :max upcoming appointments. To book another one, please contact the practice.',

    // Settings → Availability: the astrologer's own week and exceptions.
    'availability' => [
        'time' => 'Use a time between 00:00 and 23:59 (a stretch may end at 24:00).',
        'end_after_start' => 'The end must be after the start.',
        'overlap' => 'This overlaps another stretch on the same day.',
        'too_many' => 'At most :max stretches a day.',
        'too_long' => 'Time off or extra time can last at most :days days.',
        'ended' => 'This period has already ended.',
    ],
];

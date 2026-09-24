<?php

return [
    'overlap' => 'You already have an appointment at this time. Save anyway if the overlap is intended.',
    'client_fixed' => 'The client of an appointment cannot be changed.',
    'status_on_create' => 'A new appointment is always scheduled.',
    'not_cancellable' => 'Only a scheduled appointment can be cancelled.',
    'range_too_long' => 'Choose a period of at most :days days.',
    'idempotency_key_invalid' => 'The Idempotency-Key header must be 8 to 100 letters, digits or - _ : . characters.',
    'idempotency_in_progress' => 'The same request is still being processed.',
    'idempotency_key_reused' => 'This Idempotency-Key was already used for a different request.',
    'already_recorded' => 'A consultation has already been recorded from this appointment.',
    'other_client' => 'This appointment belongs to another client.',
];

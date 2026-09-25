<?php

// Email to the astrologer (docs/spec/10, "Notifikacije"). Generic on purpose:
// times and links, never a client's name, a task title or anything private.
return [
    'greeting' => 'Hello :name,',
    'settings' => 'Reminder times, the morning email and quiet hours are in [Settings → Notifications](:url).',

    'reminder' => [
        'subject' => 'Reminder: appointment on :when',
        'line' => 'You have an appointment on :day, :start – :end (:zone).',
        'action' => 'Open in the calendar',
        'private' => 'For privacy, reminders never name your client. The details open after you sign in.',
    ],

    'digest' => [
        'subject' => '{1} 1 task due today|[2,*] :count tasks due today',
        'today' => '{1} In :practice, 1 task is due today.|[2,*] In :practice, :count tasks are due today.',
        'overdue' => '{1} 1 more is overdue.|[2,*] :count more are overdue.',
        'action' => 'Open your tasks',
        'private' => 'For privacy, this email shows no task titles or client names. They open after you sign in.',
    ],

    'test' => [
        'subject' => 'Test email from :app',
        'line' => 'Email works: you asked for this message on :time (:zone).',
        'action' => 'Notification settings',
    ],

    'digest_in_quiet_hours' => 'Choose a time outside your quiet hours.',
];

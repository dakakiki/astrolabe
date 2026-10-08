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

    // The data lifecycle (Phase 8b): exports and deleting a practice.
    'export_ready' => [
        'subject' => 'Your practice export is ready',
        'line' => 'The export of :practice you asked for is ready: a ZIP with your clients, consultations, notes, files and charts.',
        'link' => 'The button works for :hours hours and only while you are signed in as the owner of the practice.',
        'kept' => 'You can also download it in Settings → Your data until :date. Then it is deleted.',
        'action' => 'Download the export',
        'private' => 'The file holds the personal data of your clients. Keep it somewhere safe.',
    ],

    'deletion_scheduled' => [
        'subject' => 'Your practice will be deleted on :date',
        'line' => ':practice is scheduled for deletion. On :date it will be deleted for good: clients, consultations, notes, files, charts and payments, and the accounts that belong only to it.',
        'cancel' => 'Until then you can cancel the deletion in the app. You can also download an export of everything first.',
        'action' => 'Open AstroLabe',
        'not_you' => 'If you did not ask for this, sign in, cancel the deletion and change your password.',
    ],

    'deletion_cancelled' => [
        'subject' => 'Your practice will not be deleted',
        'line' => 'The deletion of :practice was cancelled. Everything stays as it was.',
        'action' => 'Open AstroLabe',
    ],

    'deleted' => [
        'subject' => 'Your practice was deleted',
        'greeting' => 'Hello,',
        'line' => ':practice was deleted for good, as its owner asked. Its data is gone from the app; backup copies are overwritten within :days days.',
        'account' => 'Your AstroLabe account was deleted with it.',
        'thanks' => 'Thank you for using AstroLabe.',
    ],

    'digest_in_quiet_hours' => 'Choose a time outside your quiet hours.',
];

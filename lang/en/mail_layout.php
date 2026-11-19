<?php

// The frame of every email (Admin → Notifications → Layout): header, greeting when a
// notice has none, signature, the line under the button, and the footer. Each can be
// worded by the operator; `:app` is AstroLabe, `:practice` the practice a client email
// is from, `:year` this year.

return [
    // Shown when the email has no logo to show: AstroLabe's emails, and a practice without a usable logo.
    'header' => ':app',
    'greeting' => 'Hello!',
    // Under the email's text; a line break is kept.
    'salutation' => "Regards,\n:app",
    'subcopy' => 'If the ":actionText" button does not work, copy this address into your browser:',
    'settings_link' => 'Choose which notifications you get',
    'footer' => '© :year :app. All rights reserved.',
    'footer_client' => ':practice uses :app for appointments and its client portal.',
];

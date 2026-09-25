<?php

use Illuminate\Support\Facades\Schedule;

// GeoNames publishes daily; a monthly refresh picks up new and renamed places
// without touching birth details already saved (those are frozen copies).
Schedule::command('countries:import')->monthlyOn(1, '03:15');
Schedule::command('places:import', ['--fresh'])->monthlyOn(1, '03:30')->withoutOverlapping();

// Email to astrologers (docs/spec/10, "Notifikacije"): each run sends only what
// is due and claims it first, so a missed or doubled minute sends nothing twice.
Schedule::command('notifications:send-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:send-digests')->everyMinute()->withoutOverlapping();

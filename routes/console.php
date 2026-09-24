<?php

use Illuminate\Support\Facades\Schedule;

// GeoNames publishes daily; a monthly refresh picks up new and renamed places
// without touching birth details already saved (those are frozen copies).
Schedule::command('places:import', ['--fresh'])->monthlyOn(1, '03:30')->withoutOverlapping();

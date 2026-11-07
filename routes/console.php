<?php

use App\Domain\Rentals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/* Flag newly overdue payment-schedule items and notify customer, supplier, cashier and admins.
   Rentals are never cancelled automatically. Also runs when the cashier/admin dashboards load. */
Artisan::command('partyrent:scan-overdue', function () {
    $n = Rentals::scanOverdue();
    $this->info($n.' newly overdue schedule item(s) flagged.');
})->purpose('Flag overdue rental payments and notify everyone involved');

Schedule::command('partyrent:scan-overdue')->hourly();

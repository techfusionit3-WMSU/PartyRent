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

/* Shortfall cases (change list C12): proposals past their deadline go to PartyRent support. Also runs on page loads. */
Artisan::command('partyrent:escalate-cases', function () {
    $this->info(\App\Domain\Shortfalls::escalateDue().' case(s) escalated to support.');
})->purpose('Escalate shortfall cases whose proposal deadline passed');

Schedule::command('partyrent:escalate-cases')->everyFifteenMinutes();

/* Scheduled supplier payouts: when a shop's period (15 days or a month) ends, its earnings become one payout. */
Artisan::command('partyrent:payouts', function () {
    $this->info(\App\Domain\Payouts::closeDue().' payout(s) prepared.');
})->purpose('Prepare the supplier payouts whose period ended');

Schedule::command('partyrent:payouts')->dailyAt('00:30');

/* Check the mail setup: php artisan partyrent:mail-test you@gmail.com */
Artisan::command('partyrent:mail-test {to?}', function (?string $to = null) {
    $to = $to ?: config('mail.from.address');
    $this->line('Mailer: '.config('mail.default').' via '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').' as '.config('mail.mailers.smtp.username'));
    try {
        \Illuminate\Support\Facades\Mail::raw('If you can read this, PartyRent can send email (verification and password reset links will arrive too).', fn ($m) => $m->to($to)->subject('PartyRent mail test'));
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $this->error('Not sent: '.strtok($msg, "\n"));
        if (str_contains($msg, '535')) {
            $this->warn('Gmail rejected the login. Use a 16-character App Password (not your normal password), with no spaces, in MAIL_PASSWORD, then run: php artisan config:clear');
        }

        return 1;
    }
    $this->info(config('mail.default') === 'log' ? 'Written to storage/logs/laravel.log (MAIL_MAILER=log, nothing was emailed).' : 'Sent to '.$to.'. Check Inbox, Spam and Promotions.');
})->purpose('Send a test email to check the mail settings');

/* Personal shops: a damage charge the customer did not answer within 48 hours counts as accepted. */
Schedule::call(fn () => \App\Domain\Damage::expireReviews())->hourly()->name('damage-reviews');

/* An accepted request whose first payment is not made within 24 hours is cancelled and its items released. */
Schedule::call(fn () => \App\Domain\Rentals::expireUnpaid())->everyFiveMinutes()->name('expire-unpaid');

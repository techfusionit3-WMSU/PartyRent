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

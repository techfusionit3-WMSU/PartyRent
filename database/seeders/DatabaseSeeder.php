<?php

namespace Database\Seeders;

/**
 * Clean start: settings, categories, PartyRent payment accounts and the staff accounts
 * (superadmin, admins, cashier). No customers, suppliers, listings or bookings.
 * The full demo data lives in DemoSeeder (used by the tests): php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends DemoSeeder
{
    protected bool $staffOnly = true;

    public function run(): void
    {
        $this->settings();
        $this->categories();
        $this->users();
    }
}

<?php

namespace Database\Seeders;

/**
 * `php artisan migrate:fresh --seed` loads the full demo data from the reference prototype (seed.js v4):
 * the 10 demo accounts (password demo123), suppliers, listings, bookings and payments.
 *
 * For a clean start (settings, categories and the PartyRent admin accounts only) set SEED_DEMO=false.
 */
class DatabaseSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->staffOnly = ! filter_var(env('SEED_DEMO', true), FILTER_VALIDATE_BOOL);
        parent::run();
    }
}

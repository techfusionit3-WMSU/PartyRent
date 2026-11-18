<?php

namespace Database\Seeders;

/**
 * `php artisan migrate:fresh --seed` gives a clean start: settings, categories and the PartyRent admin
 * accounts only (Mark, Paolo, Patricia; password demo123). No demo customers, suppliers or shop staff.
 *
 * To load the full demo data from the reference prototype (customers, suppliers, listings, bookings, payments)
 * set SEED_DEMO=true. The tests use DemoSeeder directly for their fixtures.
 */
class DatabaseSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->staffOnly = ! filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL);
        parent::run();
    }
}

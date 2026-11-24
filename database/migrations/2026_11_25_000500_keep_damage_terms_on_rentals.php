<?php

use App\Domain\Damage;
use App\Models\RentalRequest;
use Illuminate\Database\Migrations\Migration;

/**
 * Rentals keep the damage fee of every rented item as agreed when booked (pricing.damageTerms; packages broken into
 * their items). New bookings store it at checkout; open rentals made before this get today's terms now, so a later
 * change by the shop no longer reaches them. Closed rentals are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        RentalRequest::whereNotIn('status', ['completed', 'declined', 'cancelled'])->with('supplier')->get()
            ->each(function (RentalRequest $r) {
                $p = (array) $r->pricing;
                if ($p && empty($p['damageTerms'])) {
                    $p['damageTerms'] = Damage::termsForLines($r->lines ?? []);
                    $r->forceFill(['pricing' => $p])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // the kept terms stay: they are part of each rental's record
    }
};

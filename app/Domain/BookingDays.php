<?php

namespace App\Domain;

use App\Models\BookingDay;
use App\Models\RentalRequest;
use Illuminate\Support\Facades\DB;

/**
 * booking_days: one row per product, per day, per request line (packages expanded into their rental
 * components). It mirrors rental_requests.lines so stock can be locked and summed in SQL
 * (README "With a real database"). Call sync() after any change to a request's lines.
 */
class BookingDays
{
    public static function sync(RentalRequest $r): void
    {
        DB::transaction(function () use ($r) {
            BookingDay::where('rental_request_id', $r->id)->delete();
            $rows = [];
            foreach ($r->lines ?? [] as $l) {
                $time = Slots::time($l);
                foreach (array_keys($l['days'] ?? []) as $date) {
                    foreach (inv()->unitsForLine($l, $date) as $pid => $qty) {
                        $rows[] = [
                            'rental_request_id' => $r->id, 'line_id' => (string) ($l['id'] ?? ''), 'product_id' => $pid, 'day' => $date, 'qty' => $qty,
                            'time_from' => $time['start'] ?? null, 'time_to' => $time['end'] ?? null,
                            'inventory_location_id' => ! empty($l['sourceId']) ? (int) $l['sourceId'] : null, 'dest_id' => $l['destId'] ?? null,
                        ];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                BookingDay::insert($chunk);
            }
        });
    }

    public static function syncAll(): void
    {
        RentalRequest::query()->each(fn ($r) => self::sync($r));
    }
}

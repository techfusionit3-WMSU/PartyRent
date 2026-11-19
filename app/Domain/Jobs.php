<?php

namespace App\Domain;

use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Field work of a shop, by day: handing items over (delivery or customer pickup), getting them back, and
 * inspecting returns. Used by Fulfillment & Returns (owner / front desk) and My Jobs (Field Crew).
 */
class Jobs
{
    public const BEFORE_RELEASE = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'];

    /** Active requests of the shop with the customer loaded. */
    public static function active(Supplier $s): Collection
    {
        return RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with(['customer', 'payments'])->get();
    }

    /**
     * [{d: date, kind: Delivery|Pickup|Return|Inspect return, r: RentalRequest}] up to $days ahead, oldest first.
     * Late jobs (date before today) stay in the list until done.
     */
    public static function list(Collection $reqs, int $days = 14): array
    {
        $T = today_iso();
        $out = [];
        foreach ($reqs as $r) {
            if (in_array($r->status, self::BEFORE_RELEASE, true)) {
                $out[] = ['d' => $r->start_date, 'kind' => $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup', 'r' => $r];
            }
            if (in_array($r->status, [...self::BEFORE_RELEASE, 'ongoing'], true)) {
                $out[] = ['d' => add_days($r->end_date, 1), 'kind' => 'Return', 'r' => $r];
            }
            if ($r->status === 'return_pending') {
                $out[] = ['d' => $T, 'kind' => 'Inspect return', 'r' => $r];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['d'], $b['d']));

        return array_values(array_filter($out, fn ($e) => $e['d'] <= add_days($T, $days)));
    }

    /** Is this job the crew's to do on the road (deliveries and returns of delivered items)? */
    public static function onTheRoad(array $job): bool
    {
        return $job['kind'] === 'Delivery' || ($job['kind'] === 'Return' && $job['r']->fulfillment === 'delivery');
    }
}

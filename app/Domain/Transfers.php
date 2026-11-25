<?php

namespace App\Domain;

use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RentalTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moving stock between branches for a combined rental (see App\Domain\Branches).
 *
 * A combined rental borrows units from nearby branches; each (item, lending branch) gets one RentalTransfer:
 *   planned  — the lending branch must send the units to the home branch before the due date (the day before the
 *              rental starts). The units stay booked at the lending branch for the rental dates.
 *   sent     — the lending branch sent them (who, when).
 *   received — the home branch got them (who, when).
 *   After the rental (completed, cancelled or declined) the home branch closes it:
 *   returned — sent back to the lending branch (its stock count never changed), or
 *   kept     — they stay at the home branch: the units move from the lending branch's stock to the home branch's
 *              (StockLocations::transfer, recorded as a stock movement).
 *   cancelled — the rental was cancelled before anything was sent, or a re-plan no longer needs the loan.
 * Every step is in the rental's history and the Store History (audit log), and printable as a transfer slip.
 */
class Transfers
{
    /** Bring the rental's transfers in line with what its lines borrow (called whenever a rental is saved). */
    public static function sync(RentalRequest $r): void
    {
        if (! $r->supplier_id || ! $r->branch_id) {
            return;
        }
        $open = RentalTransfer::where('rental_request_id', $r->id)->get();
        if (in_array($r->status, ['cancelled', 'declined'], true)) {
            $open->where('status', 'planned')->each->update(['status' => 'cancelled', 'note' => 'Rental '.$r->status]);

            return;
        }
        $want = Branches::borrowed($r); // [pid => [loc => qty]]
        $due = add_days($r->start_date, -1) < today_iso() ? today_iso() : add_days($r->start_date, -1);
        foreach ($want as $pid => $byLoc) {
            foreach ($byLoc as $loc => $qty) {
                $t = $open->first(fn ($x) => $x->product_id === (int) $pid && $x->from_location_id === (int) $loc && $x->status !== 'cancelled');
                if (! $t) {
                    RentalTransfer::create(['rental_request_id' => $r->id, 'supplier_id' => $r->supplier_id, 'product_id' => $pid, 'from_location_id' => $loc,
                        'to_location_id' => $r->branch_id, 'qty' => $qty, 'status' => 'planned', 'due_on' => $due]);
                } elseif ($t->status === 'planned') {
                    $t->update(['qty' => $qty, 'to_location_id' => $r->branch_id, 'due_on' => $due]);
                }
            }
        }
        // planned loans no longer needed (the rental was re-planned or moved)
        $open->where('status', 'planned')->filter(fn ($t) => ! isset($want[$t->product_id][$t->from_location_id]))->each->update(['status' => 'cancelled', 'note' => 'No longer needed']);
    }

    /** Can $u act on this transfer step? Sending = the lending branch; receiving and closing = the home branch. */
    public static function can(User $u, RentalTransfer $t, string $step): bool
    {
        $ids = $u->branchIds();
        if (! $u->orgCan('locations.manage') && ! $u->orgCan('inventory.manage') && ! $u->orgCan('fulfillment.update')) {
            return false;
        }
        $at = $step === 'send' ? $t->from_location_id : $t->to_location_id;

        return $ids === null || in_array($at, $ids, true);
    }

    /** One step: send | receive | return | keep. */
    public static function step(RentalTransfer $t, string $step, User $by, string $note = ''): array
    {
        $r = $t->rentalRequest;
        $ended = in_array($r->status, ['completed', 'cancelled', 'declined'], true);
        $need = ['send' => ['planned'], 'receive' => ['sent'], 'return' => ['received', 'sent'], 'keep' => ['received']][$step] ?? null;
        if (! $need) {
            return ['ok' => false, 'error' => 'Unknown step.'];
        }
        if (! in_array($t->status, $need, true)) {
            return ['ok' => false, 'error' => 'This transfer is '.mb_strtolower($t->statusLabel()).'.'];
        }
        if ($step === 'send' && $ended) {
            return ['ok' => false, 'error' => 'The rental has ended; nothing needs to be sent.'];
        }
        if (in_array($step, ['return', 'keep'], true) && ! $ended && $t->status === 'received') {
            return ['ok' => false, 'error' => 'Close the transfer after the rental ends (the items are still in use).'];
        }
        $p = Product::find($t->product_id);
        $what = $t->qty.' × '.($p?->name ?? 'item').' '.$t->from?->name.' → '.$t->to?->name;
        $res = DB::transaction(function () use ($t, $step, $by, $note, $p) {
            $now = now();
            $data = match ($step) {
                'send' => ['status' => 'sent', 'sent_at' => $now, 'sent_by' => $by->id],
                'receive' => ['status' => 'received', 'received_at' => $now, 'received_by' => $by->id],
                'return' => ['status' => 'returned', 'closed_at' => $now, 'closed_by' => $by->id],
                'keep' => ['status' => 'kept', 'closed_at' => $now, 'closed_by' => $by->id],
            };
            if ($step === 'keep') {
                $mv = StockLocations::transfer($p, $t->from_location_id, $t->to_location_id, $t->qty, 'Kept after rental '.$t->rentalRequest->code);
                if (! $mv['ok']) {
                    return $mv;
                }
            }
            $t->update($data + ($note !== '' ? ['note' => mb_substr($note, 0, 250)] : []));

            return ['ok' => true];
        });
        if (! $res['ok']) {
            return $res;
        }
        $label = ['send' => 'Sent stock to the home branch', 'receive' => 'Received stock from another branch', 'return' => 'Sent borrowed stock back', 'keep' => 'Kept borrowed stock at the home branch'][$step];
        Rentals::history($r, $r->status, $label.': '.$what);
        Notifier::audit($label, $r->code.' · '.$what);

        return ['ok' => true];
    }

    /**
     * Loans that can no longer be covered (the lending branch closed, or its stock dropped) on an open rental:
     * [['transfer' => RentalTransfer, 'why' => string], …]. Shown on the rental with "Re-plan branches".
     */
    public static function problems(RentalRequest $r): array
    {
        if (! in_array($r->status, array_merge(['pending'], Inventory::RESERVING), true)) {
            return [];
        }
        $out = [];
        $bad = collect(inv()->check($r->lines ?? [], $r->id)['bad'])->filter(fn ($b) => ! empty($b['location_id']));
        foreach (RentalTransfer::where('rental_request_id', $r->id)->where('status', 'planned')->with('from')->get() as $t) {
            if (! $t->from?->active) {
                $out[] = ['transfer' => $t, 'why' => ($t->from?->name ?? 'The lending branch').' is closed'];
            } elseif ($b = $bad->first(fn ($x) => (int) $x['location_id'] === $t->from_location_id && (int) $x['product_id'] === $t->product_id)) {
                $out[] = ['transfer' => $t, 'why' => $t->from->name.' now has only '.$b['available'].' × '.$b['name'].' on '.fmt_date($b['date'])];
            }
        }

        return $out;
    }
}

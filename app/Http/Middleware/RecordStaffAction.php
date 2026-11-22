<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change a shop's people make is kept with who did it and when (tester feedback 2026-11-22).
 * After a successful write on a shop / cashier / case page:
 *  - the shop's activity log gets a row (owner: Activity Log page), and
 *  - when it is about a rental, the rental's timeline gets the step too (unless the action already wrote a
 *    status step with the person's name, e.g. Accept).
 * Failed actions (validation error, "error" flash, 4xx/5xx, {ok:false}) are not logged.
 */
class RecordStaffAction
{
    /** Route name => what happened (":ref" = the payment / item it is about). */
    public const LABELS = [
        'supplier.requests.accept' => 'Accepted the request',
        'supplier.requests.decline' => 'Declined the request',
        'supplier.requests.advance' => 'Moved the rental to the next step',
        'supplier.requests.complete' => 'Completed the rental',
        'supplier.requests.cash' => 'Recorded a cash payment',
        'supplier.requests.inspection' => 'Saved the return inspection',
        'supplier.requests.notes' => 'Updated the internal notes',
        'supplier.requests.remind' => 'Sent a payment reminder',
        'supplier.requests.schedule' => 'Answered a schedule change request',
        'supplier.requests.settle' => 'Settled the deposit and charges',
        'supplier.requests.source' => 'Arranged stock from another source',
        'supplier.payments.verify' => 'Confirmed payment :ref',
        'supplier.payments.reject' => 'Rejected payment :ref',
        'cashier.verify' => 'Confirmed payment :ref',
        'cashier.reject' => 'Rejected payment :ref',
        'cashier.record.store' => 'Recorded a payment or refund',
        'supplier.shortfalls.store' => 'Opened a problem case',
        'cases.propose' => 'Proposed a solution on case :ref',
        'cases.respond' => 'Answered a proposal on case :ref',
        'cases.settle' => 'Settled case :ref',
        'supplier.products.store' => 'Added a product',
        'supplier.products.update' => 'Edited product :ref',
        'supplier.products.status' => 'Changed the status of :ref',
        'supplier.packages.store' => 'Added a package',
        'supplier.packages.update' => 'Edited package :ref',
        'supplier.packages.active' => 'Turned package :ref on / off',
        'supplier.packages.delete' => 'Deleted package :ref',
        'supplier.inventory.restock' => 'Restocked :ref',
        'supplier.inventory.condition' => 'Changed the condition count of :ref',
        'supplier.inventory.stock' => 'Changed the stock per location of :ref',
        'supplier.inventory.hold' => 'Held units of :ref',
        'supplier.inventory.release' => 'Released held units',
        'supplier.locations.transfer' => 'Moved stock of :ref between locations',
        'supplier.discounts.store' => 'Added a discount',
        'supplier.discounts.update' => 'Edited a discount',
        'supplier.discounts.delete' => 'Deleted a discount',
        'supplier.shop.update' => 'Changed the shop settings',
        'supplier.payouts.account' => 'Changed the payout account',
        'supplier.payouts.schedule' => 'Changed the payout schedule',
        'supplier.team.store' => 'Added a team member',
        'supplier.team.update' => 'Changed a team member\'s roles or access',
        'supplier.reviews.reply' => 'Replied to a review',
        'business.bookings.quote' => 'Sent a quote',
        'business.bookings.decline' => 'Declined a booking',
        'business.bookings.cancel' => 'Cancelled a booking',
        'business.bookings.complete' => 'Completed a booking',
        'business.bookings.part-done' => 'Marked a part of an event done',
        'business.bookings.part-team' => 'Changed the team of a part',
        'business.teams.store' => 'Added a team',
        'business.teams.update' => 'Changed a team',
        'business.payments.confirm' => 'Confirmed a payment',
        'business.event-types.apply' => 'Applied for event type :ref',
        'business.event-types.request' => 'Asked PartyRent to add an event type',
    ];

    /** Not worth a log line (chat keeps its own record; switches and lookups change nothing). */
    private const SKIP = ['supplier.requests.messages.send', 'supplier.team.check', 'supplier.staff', 'module.switch', 'cashier.record'];

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        $watch = ! $request->isMethodSafe() && preg_match('/^(supplier|cashier|cases|business)\./', $name);
        if (! $watch || in_array($name, self::SKIP, true)) {
            return $next($request);
        }
        $before = RequestEvent::max('id') ?? 0;
        $response = $next($request);
        try {
            if ($this->succeeded($request, $response)) {
                $this->record($request, $name, $before);
            }
        } catch (\Throwable $e) {
            report($e); // never break the action because the log failed
        }

        return $response;
    }

    private function succeeded(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }
        $s = $request->hasSession() ? $request->session() : null;
        if ($s && ($s->get('error') || ($s->get('errors') && $s->get('errors')->any()))) {
            return false;
        }
        if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $data = json_decode((string) $response->getContent(), true);

            return ! is_array($data) || ($data['ok'] ?? true) !== false;
        }

        return true;
    }

    private function record(Request $request, string $name, int $before): void
    {
        $u = $request->user();
        if (! $u) {
            return;
        }
        $params = $request->route()->parameters();
        $rental = null;
        $ref = null;
        foreach ($params as $p) {
            if ($p instanceof RentalRequest) {
                $rental = $p;
            } elseif ($p instanceof Payment) {
                $rental = $p->rentalRequest;
                $ref = $p->code;
            } elseif ($p instanceof Model) {
                $ref ??= $p->code ?? $p->name ?? $p->label ?? null;
                $rid = $p->getAttribute('rental_request_id');
                if (! $rental && $rid) {
                    $rental = RentalRequest::find($rid);
                }
            }
        }
        if (! $rental && $request->filled('request')) {
            $rental = RentalRequest::where('code', (string) $request->input('request'))->first();
        }
        if ($name === 'supplier.requests.schedule' && isset($params['decision'])) {
            $ref = (string) $params['decision'];
        }
        $label = self::LABELS[$name] ?? ucfirst(str_replace(['.', '_'], [' · ', ' '], preg_replace('/^(supplier|cashier|business|cases)\./', '', $name)));
        $label = trim(str_replace(':ref', (string) $ref, $label));
        if ($name === 'supplier.requests.schedule' && $ref) {
            $label .= ' ('.$ref.')';
        }
        $shopId = $rental?->supplier_id ?? $u->shop()?->id;

        AuditLog::create([
            'actor_id' => $u->id, 'supplier_id' => $shopId, 'role' => mb_substr((string) $u->activeRole(session('mode')), 0, 12),
            'action' => $label, 'ref' => $rental?->code ?? ($ref ? mb_substr((string) $ref, 0, 80) : null),
            'details' => trim(($rental ? $rental->code.' · ' : '').$label), 'created_at' => now(),
        ]);
        // the rental's own timeline, unless the action already wrote a step with this person's name
        if ($rental && ! RequestEvent::where('rental_request_id', $rental->id)->where('id', '>', $before)->where('user_id', $u->id)->exists()) {
            RequestEvent::create(['rental_request_id' => $rental->id, 'status' => 'activity', 'user_id' => $u->id, 'note' => $label, 'created_at' => now()]);
        }
    }
}

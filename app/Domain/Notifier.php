<?php

namespace App\Domain;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;

/** In-app notifications and the audit trail. */
class Notifier
{
    /**
     * @param  User|int|string|null  $to  a user (id) or a staff role name: admin | cashier | superadmin
     * @param  string  $scope  the mode the alert appears in (customer | supplier | cashier | admin | superadmin)
     */
    public static function notify(User|int|string|null $to, string $scope, string $title, string $body, string $link = ''): void
    {
        if ($to === null) {
            return;
        }
        $isRole = is_string($to) && ! ctype_digit($to);
        AppNotification::create([
            'user_id' => $isRole ? null : ($to instanceof User ? $to->id : (int) $to),
            'role' => $isRole ? $to : null,
            'scope' => $scope,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'read_by' => [],
            'created_at' => now(),
        ]);
    }

    /**
     * Money alerts for one shop (Services.notifyFinance): its cashiers (cashier workspace); a shop without a
     * cashier gets them in the supplier workspace of the owner, who verifies payments themselves.
     */
    public static function finance(\App\Models\Supplier $s, string $title, string $body, string $link = ''): void
    {
        $cashiers = \App\Models\ShopStaff::where('supplier_id', $s->id)->withRole('cashier')->where('status', 'active')->pluck('user_id');
        if ($cashiers->isEmpty()) {
            self::notify($s->owner_id, 'supplier', $title, $body, $link);

            return;
        }
        foreach ($cashiers as $uid) {
            self::notify($uid, 'cashier', $title, $body, $link);
        }
    }

    /** Money PartyRent itself must handle (refunds of money it holds, transfers to check): its cashiers and admins. */
    /** Money to check, send or record on PartyRent's side: PartyRent's cashier does it (admins only see records). */
    public static function platformFinance(string $title, string $body, string $link = ''): void
    {
        self::notify('cashier', 'cashier', $title, $body, $link);
    }

    /** Notifications visible to a user in their current mode (superadmin also sees admin alerts). */
    public static function for(User $u, ?string $mode): Collection
    {
        $role = $u->activeRole($mode);

        return AppNotification::query()
            ->where(function ($q) use ($u, $role) {
                $q->where(fn ($d) => $d->where('user_id', $u->id)->where('scope', $role));
                // Role-wide alerts are for PartyRent's staff; a shop's own cashier only gets their shop's.
                if (! ($role === 'cashier' && $u->isShopCashier())) {
                    $q->orWhere('role', $role);
                }
                // Shop staff see the shop's notifications (sent to the owner). Event company staff get their own,
                // sent to their role (App\Domain\EventRoles), so a crew member is not flooded with payments.
                if ($role === 'supplier' && ($shop = $u->shop()) && $shop->owner_id !== $u->id && ! $shop->isOrganization()) {
                    $q->orWhere(fn ($d) => $d->where('user_id', $shop->owner_id)->where('scope', 'supplier'));
                }
                if ($role === 'superadmin') {
                    $q->orWhere('role', 'admin');
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)->get();
    }

    public static function markRead(iterable $ids, int $uid): void
    {
        foreach (AppNotification::whereIn('id', collect($ids)->all())->get() as $n) {
            if (! $n->isReadBy($uid)) {
                $n->read_by = array_merge($n->read_by ?? [], [$uid]);
                $n->save();
            }
        }
    }

    /**
     * Platform audit line. With $shop (['supplier_id', 'type', 'category', 'ref', 'auto']) it also goes into that
     * store's history: for changes no one in the shop made (the gateway confirmed a payment, a payout was
     * prepared, the customer cancelled, PartyRent support decided). 'auto' => true means no person did it.
     * Shop staff actions are recorded by RecordStaffAction, so they never pass $shop (no duplicate lines).
     */
    public static function audit(string $action, string $details, ?User $actor = null, array $shop = []): void
    {
        $auto = (bool) ($shop['auto'] ?? false);
        $actor = $auto ? null : ($actor ?? auth()->user());
        $row = [
            'actor_id' => $actor?->id,
            'role' => $actor ? $actor->activeRole(session('mode')) : 'system',
            'action' => $action,
            'details' => $details,
            'created_at' => now(),
        ];
        if (! empty($shop['supplier_id'])) {
            $row += [
                'supplier_id' => $shop['supplier_id'], 'type' => $shop['type'] ?? ($auto ? 'system' : 'updated'), 'category' => $shop['category'] ?? null,
                'ref' => isset($shop['ref']) ? mb_substr((string) $shop['ref'], 0, 80) : null,
                'role_label' => $actor ? ($actor->hasRole('admin') || $actor->hasRole('superadmin') || $actor->hasRole('cashier') && ! $actor->staffOf ? 'PartyRent '.ucfirst((string) $actor->activeRole(session('mode'))) : 'Customer') : 'PartyRent (automatic)',
            ];
        }
        AuditLog::create($row);
    }
}

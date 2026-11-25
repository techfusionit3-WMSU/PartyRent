<?php

namespace App\Models;

use App\Domain\Finance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RentalRequest extends Model
{
    public const STATUS = [
        'pending' => ['Pending', 'b-gold'],
        'accepted' => ['Accepted', 'b-sky'],
        'declined' => ['Declined', 'b-red'],
        'cancelled' => ['Cancelled', 'b-gray'],
        'in_progress' => ['In Progress', 'b-purple'],
        'ready_pickup' => ['Ready for Pickup', 'b-teal'],
        'out_delivery' => ['Out for Delivery', 'b-teal'],
        'ongoing' => ['Ongoing Rental', 'b-pink'],
        'return_pending' => ['Return Pending', 'b-gold'],
        'inspection' => ['Under Inspection', 'b-purple'],
        'completed' => ['Completed', 'b-green'],
    ];

    /** Statuses whose lines consume stock. */
    public const RESERVING = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'pricing' => 'array',
            'installment_plan' => 'array', 'destinations' => 'array', 'services' => 'array', 'allocation' => 'array',
            'address_parts' => 'array',
            'fulfillment_log' => 'array',
            'inspection' => 'array',
            'overdue_flags' => 'array',
            'cancel_free' => 'boolean', 'pay_by' => 'datetime', 'reserved_at' => 'datetime',
            'chat_customer_seen_at' => 'datetime', 'chat_supplier_seen_at' => 'datetime',
        ];
    }

    /** Accepted, but the items are not held until the first payment is verified. */
    public function awaitingDownPayment(): bool
    {
        return $this->status === 'accepted' && ! $this->reserved_at;
    }

    /** Does this request hold stock? Every reserving status, except an accepted one still waiting for its down payment. */
    public function holdsStock(): bool
    {
        return in_array($this->status, self::RESERVING, true) && ! $this->awaitingDownPayment();
    }

    /** A home-based supplier's exact address/pin is shared with the customer only while an accepted pickup is under way. */
    public function revealsPickupLocation(): bool
    {
        return $this->fulfillment === 'pickup' && in_array($this->status, \App\Domain\Finance::ACTIVE, true);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    /** The supplier's branch that fulfils this rental (its stock, its address as delivery origin / pickup place). */
    public function branch(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'branch_id'); }
    public function transfers(): HasMany { return $this->hasMany(RentalTransfer::class)->orderBy('id'); }

    /**
     * A shop's rentals as the signed-in user may see them (App\Support\BranchScope): the branch the dashboard shows,
     * else all the user's branches, else every branch. $lending also lists rentals their branches lend stock to.
     * $view = false ignores the switcher (the user's whole scope).
     */
    public function scopeForShop($q, Supplier $s, bool $lending = false, bool $view = true)
    {
        $ids = $view ? \App\Support\BranchScope::viewIds() : \App\Support\BranchScope::allowed();
        $q->where('supplier_id', $s->id);
        if ($ids !== null) {
            $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->when($lending, fn ($x) => $x->orWhereHas('transfers', fn ($t) => $t->whereIn('from_location_id', $ids)->where('status', '!=', 'cancelled'))));
        }

        return $q;
    }

    protected static function booted(): void
    {
        // combined rentals: keep the stock moves between branches in step with the lines (App\Domain\Transfers)
        static::saved(function (RentalRequest $r) {
            if ($r->wasRecentlyCreated || $r->wasChanged(['lines', 'branch_id', 'status', 'start_date'])) {
                \App\Domain\Transfers::sync($r);
            }
        });
    }
    public function payout(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(Payout::class); }
    public function messages(): HasMany { return $this->hasMany(RequestMessage::class)->orderBy('id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class)->orderBy('submitted_at')->orderBy('id'); }
    public function events(): HasMany { return $this->hasMany(RequestEvent::class)->orderBy('id'); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    public static function statusLabel(string $s): string { return self::STATUS[$s][0] ?? $s; }
    public static function statusClass(string $s): string { return self::STATUS[$s][1] ?? 'b-gray'; }

    public function isActive(): bool { return in_array($this->status, Finance::ACTIVE, true); }

    /** Memoized financial summary (cleared by refresh()). */
    public function fin(): array
    {
        return $this->finCache ??= Finance::summary($this);
    }

    public function refresh()
    {
        $this->finCache = null;

        return parent::refresh();
    }

    public ?array $finCache = null;

    public function dates(): array
    {
        $all = [];
        foreach ($this->lines ?? [] as $l) {
            $all = array_merge($all, array_keys($l['days'] ?? []));
        }
        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    public function totalUnits(): int
    {
        $n = 0;
        foreach ($this->lines ?? [] as $l) {
            $n += array_sum(array_map('intval', $l['days'] ?? []));
        }

        return $n;
    }
}

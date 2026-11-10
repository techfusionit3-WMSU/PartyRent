<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money PartyRent releases to a supplier once a rental is done (see App\Domain\Payouts). */
class Payout extends Model
{
    public const METHOD_LABEL = ['gcash' => 'GCash', 'bank' => 'Bank transfer'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'income' => 'float', 'commission_pct' => 'float', 'commission' => 'float', 'delivery' => 'float', 'voucher' => 'float', 'compensation' => 'float', 'net' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }

    public function isPaid(): bool { return $this->status === 'paid'; }

    /** Same shape as Payouts::breakdown(), from the frozen payout row. */
    public function breakdown(): array
    {
        return [
            'income' => $this->income, 'items' => r2($this->income - $this->delivery), 'delivery' => $this->delivery, 'voucher' => $this->voucher,
            'pct' => $this->commission_pct, 'commission' => $this->commission, 'compensation' => $this->compensation, 'net' => $this->net,
        ];
    }
    public function methodLabel(): string { return self::METHOD_LABEL[$this->method] ?? '—'; }
}

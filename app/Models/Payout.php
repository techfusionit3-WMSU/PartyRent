<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's EARNINGS from one completed rental (see App\Domain\Payouts). They are paid in the shop's next
 * scheduled payout (PayoutBatch, every 15 days or monthly). status: pending (waiting) | scheduled | paid.
 */
class Payout extends Model
{
    public const METHOD_LABEL = ['gcash' => 'GCash', 'maya' => 'Maya', 'ewallet' => 'E-wallet', 'bank' => 'Bank transfer', 'card' => 'Debit card'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'income' => 'float', 'commission_pct' => 'float', 'commission' => 'float', 'delivery' => 'float', 'voucher' => 'float', 'compensation' => 'float', 'earned' => 'float', 'cash_kept' => 'float', 'net' => 'float',
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
    public function batch(): BelongsTo { return $this->belongsTo(PayoutBatch::class, 'payout_batch_id'); }

    public function isPaid(): bool { return $this->status === 'paid'; }
    public function isSending(): bool { return $this->status === 'sending'; }

    /** Same shape as Payouts::breakdown(), from the frozen payout row. */
    public function breakdown(): array
    {
        return [
            'income' => $this->income, 'items' => r2($this->income - $this->delivery), 'delivery' => $this->delivery, 'voucher' => $this->voucher,
            'pct' => $this->commission_pct, 'commission' => $this->commission, 'compensation' => $this->compensation,
            'earned' => $this->earned, 'cashKept' => $this->cash_kept, 'net' => $this->net,
        ];
    }
    public function methodLabel(): string { return self::METHOD_LABEL[$this->method] ?? '—'; }
}

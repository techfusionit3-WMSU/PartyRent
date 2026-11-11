<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const TYPE_LABEL = [
        'reservation' => 'Reservation Fee', 'down' => 'Down Payment', 'installment' => 'Installment',
        'balance' => 'Remaining Balance', 'full' => 'Full Payment', 'deposit' => 'Security Deposit',
        'deposit_refund' => 'Deposit Refund', 'compensation' => 'Damage Compensation', 'refund' => 'Rental Refund',
    ];

    public const METHOD_LABEL = [
        'cod' => 'Cash on Delivery', 'cash' => 'Cash (Over the Counter)', 'bank' => 'Bank Transfer / GCash / Maya', 'debit' => 'Debit Card',
        'online' => 'Online (GCash, Maya, card)',
    ];

    /** Methods whose reference number comes from a bank or e-wallet and must not be reused. */
    public const REF_CHECKED = ['bank', 'debit'];

    /** Inflow types (refunds go the other way and may reuse a reference). */
    public const INFLOW = ['reservation', 'down', 'installment', 'balance', 'full', 'deposit', 'compensation'];

    public const STATUS_LABEL = [
        'awaiting' => 'Awaiting Online Payment', 'pending' => 'Pending Verification', 'verified' => 'Verified', 'rejected' => 'Rejected',
    ];

    public const ARR_LABEL = [
        'full' => 'Full Payment', 'down' => 'Down Payment', 'installment' => 'Installment Payment', 'reservation' => 'Reservation Fee + Balance',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn (Payment $p) => $p->reference_key = self::refKey((string) $p->reference));
    }

    /** "bdo 123-456" and "BDO123456" are the same reference. */
    public static function refKey(string $reference): ?string
    {
        $k = mb_substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($reference)), 0, 80);

        return $k === '' ? null : $k;
    }

    /** Other live inflow payments (pending or verified) with the same bank/e-wallet reference. */
    public function duplicates()
    {
        if (! $this->reference_key || ! in_array($this->method, self::REF_CHECKED, true) || ! in_array($this->type, self::INFLOW, true)) {
            return collect();
        }

        return self::where('reference_key', $this->reference_key)->whereKeyNot($this->id)
            ->whereIn('method', self::REF_CHECKED)->whereIn('type', self::INFLOW)->whereIn('status', ['pending', 'verified'])
            ->with('rentalRequest')->get();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'gateway_fee' => 'float',
            'verified_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function payAccount(): BelongsTo { return $this->belongsTo(PayAccount::class); }

    public function typeLabel(): string { return self::TYPE_LABEL[$this->type] ?? $this->type; }
    public function methodLabel(): string { return self::METHOD_LABEL[$this->method] ?? $this->method; }
    public function isOutflow(): bool { return in_array($this->type, ['refund', 'deposit_refund'], true); }
}

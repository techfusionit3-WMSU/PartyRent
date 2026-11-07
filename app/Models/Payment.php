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
        'cod' => 'Cash on Delivery', 'cash' => 'Cash (Over the Counter)', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit Card',
    ];

    public const ARR_LABEL = [
        'full' => 'Full Payment', 'down' => 'Down Payment', 'installment' => 'Installment Payment', 'reservation' => 'Reservation Fee + Balance',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
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

    public function typeLabel(): string { return self::TYPE_LABEL[$this->type] ?? $this->type; }
    public function methodLabel(): string { return self::METHOD_LABEL[$this->method] ?? $this->method; }
    public function isOutflow(): bool { return in_array($this->type, ['refund', 'deposit_refund'], true); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout PartyRent sends a shop for a period (every 15 days or monthly): the earnings of the rentals
 * completed in that period, minus the cash the shop already collected, plus any balance carried over.
 * See App\Domain\Payouts.
 */
class PayoutBatch extends Model
{
    public const STATUS_LABEL = ['pending' => 'Being prepared', 'sending' => 'On the way', 'paid' => 'Paid', 'carried' => 'Nothing to send', 'settled' => 'Settled'];

    public const STATUS_BADGE = ['pending' => 'b-gold', 'sending' => 'b-sky', 'paid' => 'b-green', 'carried' => 'b-gray', 'settled' => 'b-green'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d',
            'earned' => 'float', 'commission' => 'float', 'cash_kept' => 'float', 'carried_in' => 'float', 'net' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function lines(): HasMany { return $this->hasMany(Payout::class)->orderBy('created_at'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }

    public function isPaid(): bool { return $this->status === 'paid'; }
    public function isSending(): bool { return $this->status === 'sending'; }
    /** The shop owes PartyRent (more cash collected than earned); taken from the next payout. */
    public function isOwed(): bool { return $this->status === 'carried' && $this->net < -0.004; }

    public function statusLabel(): string
    {
        return $this->isOwed() ? 'You owe PartyRent' : (self::STATUS_LABEL[$this->status] ?? ucfirst($this->status));
    }

    /** The status a shop sees: [label, badge]. Pending | Processing | Completed | Failed (a failed send goes back to pending). */
    public function payoutStatus(): array
    {
        return match (true) {
            $this->status === 'pending' && (bool) $this->failure => ['Failed', 'b-red'],
            $this->status === 'pending' => ['Pending', 'b-gold'],
            $this->status === 'sending' => ['Processing', 'b-sky'],
            in_array($this->status, ['paid', 'settled'], true) => ['Completed', 'b-green'],
            default => [self::STATUS_LABEL[$this->status] ?? ucfirst($this->status), self::STATUS_BADGE[$this->status] ?? 'b-gray'],
        };
    }

    public function periodLabel(): string
    {
        return fmt_range($this->period_start->toDateString(), $this->period_end->toDateString());
    }

    public function methodLabel(): string { return Payout::METHOD_LABEL[$this->method] ?? '—'; }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message between PartyRent and the payment gateway (see App\Domain\PaymentChecks). */
class PaymentTransaction extends Model
{
    public const SOURCE_LABEL = [
        'webhook' => 'Gateway webhook', 'return' => 'Customer returned', 'auto_check' => 'Automatic check',
        'manual_check' => 'Admin "Check now"', 'checkout' => 'Checkout',
    ];

    public const RESULT_LABEL = [
        'paid' => 'Paid · verified', 'review' => 'Paid · needs review', 'failed' => 'Failed', 'expired' => 'Expired · closed',
        'rejected' => 'Rejected (bad signature)', 'error' => 'Gateway unreachable', 'ignored' => 'Ignored',
    ];

    public const RESULT_BADGE = [
        'paid' => 'b-green', 'review' => 'b-gold', 'failed' => 'b-red', 'expired' => 'b-gray',
        'rejected' => 'b-red', 'error' => 'b-red', 'ignored' => 'b-gray',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'fee' => 'float', 'verified' => 'boolean'];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function eventPayment(): BelongsTo { return $this->belongsTo(EventPayment::class); }

    public function sourceLabel(): string { return self::SOURCE_LABEL[$this->source] ?? ucfirst($this->source); }
    public function resultLabel(): string { return self::RESULT_LABEL[$this->result] ?? ucfirst($this->result); }
    public function resultBadge(): string { return self::RESULT_BADGE[$this->result] ?? 'b-gray'; }
}

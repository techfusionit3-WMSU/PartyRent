<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place a shop can be paid. The full account number is encrypted at rest and only shown masked
 * ("•••• 4567"); debit cards are saved by the card-payout provider's token only (no card number, no CVV).
 */
class PayoutAccount extends Model
{
    /** Category → provider → label, and the fields each provider asks for. */
    public const PROVIDERS = [
        'ewallet' => ['gcash' => 'GCash', 'maya' => 'Maya', 'other_ewallet' => 'Other e-wallet'],
        'bank' => ['bpi' => 'BPI', 'bdo' => 'BDO', 'other_bank' => 'Other bank'],
        'card' => ['card' => 'Debit card'],
    ];

    public const CATEGORY_LABEL = ['ewallet' => 'E-wallet', 'bank' => 'Bank account', 'card' => 'Debit card'];

    /** Account-number rules per provider: [regex on the digits, hint]. */
    public const NUMBER_RULES = [
        'gcash' => ['/^09\d{9}$/', 'the 11-digit mobile number registered to GCash (09XXXXXXXXX)'],
        'maya' => ['/^09\d{9}$/', 'the 11-digit mobile number registered to Maya (09XXXXXXXXX)'],
        'other_ewallet' => ['/^\d{6,20}$/', 'the account or mobile number (6–20 digits)'],
        'bpi' => ['/^\d{10}$/', 'the 10-digit BPI account number'],
        'bdo' => ['/^(\d{10}|\d{12})$/', 'the 10- or 12-digit BDO account number'],
        'other_bank' => ['/^\d{6,20}$/', 'the account number (6–20 digits)'],
    ];

    protected $guarded = ['id'];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'details' => 'array', 'is_default' => 'boolean'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function masked(): string
    {
        return $this->last4 ? '•••• '.$this->last4 : '••••';
    }

    /** "GCash · Juan Dela Cruz · •••• 4567" */
    public function label(): string
    {
        return $this->provider_name.' · '.$this->account_name.' · '.$this->masked();
    }

    /** suppliers.payout_method value for this account (payout records and Xendit). */
    public function method(): string
    {
        return match ($this->provider) { 'gcash' => 'gcash', 'maya' => 'maya', 'other_ewallet' => 'ewallet', 'card' => 'card', default => 'bank' };
    }

    /** Copy this account into the shop's payout_* columns: what payouts are sent to. */
    public function mirror(): void
    {
        $this->supplier->forceFill([
            'payout_method' => $this->method(),
            'payout_bank' => $this->category === 'bank' ? $this->provider_name : ($this->provider === 'other_ewallet' ? $this->provider_name : null),
            'payout_account_name' => $this->account_name,
            'payout_account_no' => $this->category === 'card' ? $this->masked() : (string) $this->account_number,
        ])->save();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of PartyRent's own collection accounts (GCash, Maya, bank) that customers transfer to. */
class PayAccount extends Model
{
    public const TYPE_LABEL = ['gcash' => 'GCash', 'maya' => 'Maya', 'bank' => 'Bank', 'other' => 'Other / QR Ph'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monthly_cap' => 'float', 'active' => 'boolean'];
    }

    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    public function scopeOrdered(Builder $q): Builder { return $q->orderBy('sort')->orderBy('id'); }

    public function typeLabel(): string { return self::TYPE_LABEL[$this->type] ?? $this->type; }

    /** "GCash 0917 555 0142" — how the account is named to cashiers and in payment records. */
    public function display(): string
    {
        return trim($this->label.' '.$this->account_no);
    }

    /** Money claimed into this account this calendar month: verified plus pending (they count against the wallet limit once sent). */
    public function receivedThisMonth(): float
    {
        return round((float) $this->payments()->whereIn('status', ['pending', 'verified'])->whereIn('type', Payment::INFLOW)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'), 2);
    }

    /** How much more it can take this month (null = no cap). */
    public function room(): ?float
    {
        return $this->monthly_cap === null ? null : max(0, round($this->monthly_cap - $this->receivedThisMonth(), 2));
    }

    /** Can a customer send $amount here now? */
    public function accepts(float $amount): bool
    {
        $room = $this->room();

        return $this->active && ($room === null || $amount <= $room + 0.001);
    }

    /** Active accounts with room left, for the customer's payment form. */
    public static function available(): \Illuminate\Support\Collection
    {
        return self::where('active', true)->ordered()->get()->filter(fn (self $a) => $a->room() === null || $a->room() > 0.009)->values();
    }
}

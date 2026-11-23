<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of history: who did what, when, in which part of the store, with old → new values.
 * Append-only: a saved row can never be changed or deleted through the app.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public const TYPES = [
        'created' => ['Created', 'b-green', 'plus'], 'updated' => ['Updated', 'b-sky', 'edit'], 'deleted' => ['Deleted', 'b-red', 'trash'],
        'approved' => ['Approved', 'b-green', 'check'], 'rejected' => ['Rejected', 'b-red', 'x'], 'cancelled' => ['Cancelled', 'b-gray', 'x'],
        'completed' => ['Completed', 'b-purple', 'check'], 'payment' => ['Payment', 'b-gold', 'card'], 'sent' => ['Sent', 'b-sky', 'send'],
        'system' => ['Automatic', 'b-gray', 'refresh'],
    ];

    public const CATEGORIES = [
        'rentals' => ['Rentals', 'list'], 'events' => ['Event bookings', 'calendar'], 'products' => ['Products', 'box'], 'packages' => ['Packages', 'package'],
        'inventory' => ['Inventory & stock', 'layers'], 'pricing' => ['Pricing & discounts', 'tag'], 'payments' => ['Payments', 'card'],
        'payouts' => ['Payouts', 'cash'], 'cases' => ['Problems & cases', 'alert'], 'settings' => ['Store settings', 'settings'],
        'team' => ['Team & roles', 'users'], 'reviews' => ['Reviews', 'star'],
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // history is a record, not a working document
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }

    public function typeLabel(): string { return self::TYPES[$this->type][0] ?? 'Activity'; }
    public function typeBadge(): string { return self::TYPES[$this->type][1] ?? 'b-gray'; }
    public function categoryLabel(): string { return self::CATEGORIES[$this->category][0] ?? 'Other'; }
    public function categoryIcon(): string { return self::CATEGORIES[$this->category][1] ?? 'activity'; }

    /** "Juan Dela Cruz · Owner", or "PartyRent (automatic)". */
    public function who(): string
    {
        return $this->actor ? $this->actor->name : 'PartyRent (automatic)';
    }
}

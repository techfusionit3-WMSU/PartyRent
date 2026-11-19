<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of an event booking's payment schedule: due → pending (proof sent) → paid (business confirmed). */
class EventPayment extends Model
{
    protected $guarded = ['id'];

    public const METHODS = ['bank' => 'Bank transfer', 'gcash' => 'GCash', 'cash' => 'Cash', 'online' => 'Paid online'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'due_date' => 'date', 'submitted_at' => 'datetime', 'paid_at' => 'datetime', 'remitted_at' => 'datetime'];
    }

    public function booking(): BelongsTo { return $this->belongsTo(EventBooking::class, 'event_booking_id'); }

    public function isOverdue(): bool
    {
        return $this->status === 'due' && $this->due_date->lt(today());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'paid' => 'Paid', 'pending' => 'Checking payment',
            default => $this->isOverdue() ? 'Overdue' : 'Due',
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'paid' => 'b-green', 'pending' => 'b-sky',
            default => $this->isOverdue() ? 'b-red' : 'b-gold',
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Booking a business for an event: inquiry → quoted → booked (quote accepted, reservation fee due)
 * → confirmed (reservation fee paid, date held) → completed. Ends early as declined / cancelled / expired.
 */
class EventBooking extends Model
{
    protected $guarded = ['id'];

    public const STATUS = [
        'inquiry' => ['New inquiry', 'b-gold'], 'quoted' => ['Quotation sent', 'b-sky'], 'booked' => ['Awaiting reservation fee', 'b-purple'],
        'confirmed' => ['Confirmed', 'b-green'], 'completed' => ['Completed', 'b-teal'],
        'declined' => ['Declined', 'b-gray'], 'cancelled' => ['Cancelled', 'b-red'], 'expired' => ['Expired', 'b-gray'],
    ];

    /** Statuses that still hold or may hold the date. */
    public const OPEN = ['inquiry', 'quoted', 'booked', 'confirmed'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date', 'quote' => 'array', 'budget' => 'float', 'guests' => 'integer',
            'quoted_at' => 'datetime', 'accepted_at' => 'datetime', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function coordinator(): BelongsTo { return $this->belongsTo(User::class, 'coordinator_id'); }
    public function package(): BelongsTo { return $this->belongsTo(EventPackage::class, 'event_package_id'); }
    public function payments(): HasMany { return $this->hasMany(EventPayment::class)->orderBy('due_date')->orderBy('id'); }
    /** The parts of the event (stages / days), in date order (App\Domain\EventParts). */
    public function parts(): HasMany { return $this->hasMany(EventBookingPart::class)->orderBy('date')->orderBy('sort'); }

    public function lastDate(): \Carbon\Carbon { return $this->parts->max('date') ?? $this->event_date; }

    /** "Sat, Dec 5, 2026" or "Oct 10 – Dec 5, 2026 · 3 parts". */
    public function whenLabel(): string
    {
        $n = $this->parts->count();
        $last = $this->lastDate();

        return $n <= 1 || $last->isSameDay($this->event_date) ? $this->event_date->format('D, M j, Y').($n > 1 ? ' · '.$n.' parts' : '') : fmt_range($this->event_date->toDateString(), $last->toDateString()).' · '.$n.' parts';
    }

    public function getRouteKeyName(): string { return 'code'; }

    public function statusLabel(): string { return self::STATUS[$this->status][0] ?? ucfirst($this->status); }
    public function statusBadge(): string { return self::STATUS[$this->status][1] ?? 'b-gray'; }

    public function total(): float { return (float) ($this->quote['total'] ?? 0); }

    public function paid(): float { return (float) $this->payments->where('status', 'paid')->sum('amount'); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN, true); }

    public function timeLabel(): string
    {
        if (! $this->start_time) {
            return '';
        }

        return \App\Domain\Slots::fmt12($this->start_time).($this->end_time ? ' – '.\App\Domain\Slots::fmt12($this->end_time) : '');
    }
}

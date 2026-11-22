<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One part of an event booking (a stage or a day) with its own date, time and venue (App\Domain\EventParts). */
class EventBookingPart extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'done_at' => 'datetime', 'price' => 'float'];
    }

    public function booking(): BelongsTo { return $this->belongsTo(EventBooking::class, 'event_booking_id'); }

    public function timeLabel(): string
    {
        $f = fn ($t) => $t ? \App\Domain\Slots::fmt12($t) : null;

        return $this->start_time ? $f($this->start_time).($this->end_time ? ' – '.$f($this->end_time) : '') : '';
    }
}

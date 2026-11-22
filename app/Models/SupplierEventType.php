<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One kind of event a business handles: its claim, its proof and the limits PartyRent verified. */
class SupplierEventType extends Model
{
    protected $guarded = ['id'];

    public const STATUS = ['pending' => ['Waiting for review', 'b-gold'], 'verified' => ['Verified', 'b-green'], 'rejected' => ['Not approved', 'b-red'], 'paused' => ['Paused', 'b-gray']];

    protected function casts(): array
    {
        return ['photos' => 'array', 'docs' => 'array', 'raise_pending' => 'boolean', 'reviewed_at' => 'datetime',
            'claimed_max_guests' => 'integer', 'claimed_per_day' => 'integer', 'past_events' => 'integer', 'max_guests' => 'integer', 'per_day' => 'integer'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function type(): BelongsTo { return $this->belongsTo(EventType::class, 'event_type_id'); }

    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function isVerified(): bool { return $this->status === 'verified'; }

    public function statusLabel(): string { return self::STATUS[$this->status][0] ?? $this->status; }

    public function statusClass(): string { return self::STATUS[$this->status][1] ?? 'b-gray'; }

    /** "Up to 250 guests · 1 event a day" (the verified limits). */
    public function limitsLabel(): string
    {
        return $this->isVerified() || $this->status === 'paused'
            ? 'Up to '.number_format((int) $this->max_guests).' guests · '.(int) $this->per_day.' event'.((int) $this->per_day === 1 ? '' : 's').' a day'
            : 'Asked for up to '.number_format($this->claimed_max_guests).' guests · '.$this->claimed_per_day.' a day';
    }
}

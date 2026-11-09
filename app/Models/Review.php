<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['media' => 'array', 'replied_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function package(): BelongsTo { return $this->belongsTo(Package::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function reports(): HasMany { return $this->hasMany(ReviewReport::class); }

    /** Reviews that count toward ratings and are shown publicly. */
    public function scopeVisible(Builder $q): Builder { return $q->whereNull('hidden_at'); }

    /** Written by a customer who actually rented through PartyRent. */
    public function isVerified(): bool { return (bool) $this->rental_request_id; }

    public function itemName(): string { return $this->product?->name ?? $this->package?->name ?? ''; }

    /** @return array<int, array{type:string,url:string,mime:string}> */
    public function mediaItems(): array
    {
        return array_map(fn ($m) => ['type' => $m['type'], 'url' => asset('storage/'.$m['path']), 'mime' => $m['mime'] ?? ''], $this->media ?? []);
    }

    public function hasMedia(): bool { return ! empty($this->media); }

    /** "Maria S." */
    public function authorName(): string
    {
        $parts = preg_split('/\s+/', trim($this->customer?->name ?? 'Customer'));

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $parts[0];
    }
}

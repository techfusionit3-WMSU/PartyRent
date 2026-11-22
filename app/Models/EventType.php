<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of event in PartyRent's catalog (App\Domain\EventTypes). */
class EventType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parts' => 'array', 'services' => 'array', 'proofs' => 'array', 'min_guests' => 'integer', 'max_guests' => 'integer'];
    }

    public function scopeActive($q) { return $q->where('status', 'active')->orderBy('sort')->orderBy('name'); }

    public function requester(): BelongsTo { return $this->belongsTo(Supplier::class, 'requested_by'); }

    public function businesses(): HasMany { return $this->hasMany(SupplierEventType::class); }

    public function guestsLabel(): string
    {
        return $this->min_guests && $this->max_guests ? $this->min_guests.'–'.$this->max_guests.' guests' : ($this->max_guests ? 'up to '.$this->max_guests.' guests' : '');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A team (crew) of an event company: runs one event part at a time (App\Domain\EventTeams). */
class EventTeam extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['members' => 'array', 'active' => 'boolean'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function parts(): HasMany { return $this->hasMany(EventBookingPart::class); }

    public function memberUsers()
    {
        return User::whereIn('id', (array) $this->members)->orderBy('name')->get();
    }
}

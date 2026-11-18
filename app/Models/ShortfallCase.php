<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shortfall / dispute case (change list C12). See App\Domain\Shortfalls. */
class ShortfallCase extends Model
{
    protected $guarded = ['id'];

    public const STEPS = ['reported' => 'Reported', 'proposed' => 'Proposed', 'countered' => 'Countered', 'agreed' => 'Agreed', 'resolved' => 'Resolved'];

    public const STATUS = [
        'reported' => ['Reported', 'b-gold'], 'proposed' => ['Proposal sent', 'b-sky'], 'countered' => ['Countered', 'b-purple'],
        'agreed' => ['Agreed', 'b-green'], 'resolved' => ['Resolved', 'b-green'], 'escalated' => ['With PartyRent support', 'b-red'],
    ];

    protected function casts(): array
    {
        return ['short' => 'array', 'resolution' => 'array', 'deadline_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function events(): HasMany { return $this->hasMany(CaseEvent::class)->orderBy('id'); }

    public function proposal(): ?CaseEvent
    {
        return $this->proposal_id ? CaseEvent::find($this->proposal_id) : null;
    }

    /** Opened by the customer ("report a problem with items") rather than by the shop (shortfall). */
    public function byCustomer(): bool
    {
        return $this->kind === 'problem';
    }

    /** One line describing the case for both sides, e.g. "3 × Chair cannot be provided on Dec 1" / "Customer reported: Damaged … — 2 × Chair". */
    public function summary(): string
    {
        $dates = collect(array_keys($this->short ?? []))->map(fn ($d) => fmt_date($d))->implode(', ');
        if ($this->byCustomer()) {
            return 'Customer reported: '.(\App\Domain\Shortfalls::PROBLEMS[$this->problem] ?? 'a problem').' — '.$this->units().' × '.$this->product->name.' ('.$dates.')';
        }

        return $this->units().' × '.$this->product->name.' cannot be provided on '.$dates;
    }

    public function units(): int
    {
        return (int) array_sum($this->short ?? []);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['resolved'], true);
    }
}

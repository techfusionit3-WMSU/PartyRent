<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's identity / payment-account / insurance check (App\Domain\Verifications). */
class CustomerVerification extends Model
{
    public const STATUS_LABEL = ['not_submitted' => 'Not Submitted', 'pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'resubmit' => 'Requires Resubmission'];

    public const STATUS_BADGE = ['not_submitted' => 'b-gray', 'pending' => 'b-gold', 'approved' => 'b-green', 'rejected' => 'b-red', 'resubmit' => 'b-red'];

    public const ID_TYPES = ['philsys' => 'PhilSys National ID', 'passport' => 'Passport', 'drivers' => "Driver's License", 'umid' => 'UMID', 'sss' => 'SSS ID', 'prc' => 'PRC ID', 'postal' => 'Postal ID', 'voters' => "Voter's ID"];

    public const PAY_PROVIDERS = ['GCash', 'Maya', 'BPI', 'BDO', 'Metrobank', 'UnionBank', 'Landbank', 'Security Bank', 'Other bank', 'Other e-wallet'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['history' => 'array', 'id_verified_at' => 'datetime', 'pay_verified_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'insurance_expires' => 'date'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function isApproved(): bool { return $this->status === 'approved'; }

    /** May the customer send (again)? */
    public function canSubmit(): bool { return in_array($this->status, ['not_submitted', 'rejected', 'resubmit'], true); }

    public function statusLabel(): string { return self::STATUS_LABEL[$this->status] ?? ucfirst($this->status); }

    /** Trust indicators shown to shops: [label, badge class]. */
    public function trustBadges(): array
    {
        $b = [];
        if ($this->id_verified_at) {
            $b[] = ['Identity Verified', 'b-green'];
        }
        if ($this->pay_verified_at) {
            $b[] = ['Payment Account Verified', 'b-green'];
        }
        if ($this->insurance_status === 'verified') {
            $b[] = ['Insurance Verified', 'b-green'];
        } elseif ($this->insurance_status === 'submitted') {
            $b[] = ['Insurance Submitted', 'b-sky'];
        }

        return $b;
    }

    public function log(string $what, ?User $by = null): void
    {
        $h = $this->history ?? [];
        $h[] = ['at' => now()->toIso8601String(), 'what' => $what, 'by' => $by?->name];
        $this->history = array_slice($h, -30);
    }
}

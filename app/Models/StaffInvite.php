<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation for an email to join a shop's team. The emailed link carries a secret token; a new email also
 * gets a 6-digit code to type in. Only hashes are stored. Deleted once accepted, declined or cancelled.
 */
class StaffInvite extends Model
{
    public const TTL_HOURS = 48;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'roles' => 'array', 'branch_ids' => 'array'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }

    private static function hash(string $v): string
    {
        return hash_hmac('sha256', $v, config('app.key'));
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', self::hash($token))->first();
    }

    /** Fresh link token and code (resets attempts and expiry); returns both in plain text for the email. */
    public function issue(): array
    {
        $token = Str::random(48);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->fill([
            'token_hash' => self::hash($token), 'code_hash' => self::hash($code), 'attempts' => 0,
            'expires_at' => now()->addHours(self::TTL_HOURS), 'last_sent_at' => now(),
        ])->save();

        return [$token, $code];
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hash(preg_replace('/\D/', '', $code)));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function secondsUntilResend(): int
    {
        return max(0, self::RESEND_SECONDS - (int) $this->last_sent_at->diffInSeconds(now()));
    }

    /** Roles offered in this invitation, main role first. */
    public function roleList(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->role], (array) $this->roles))));
    }

    public function roleLabel(): string
    {
        return ShopStaff::rolesLabel($this->roleList());
    }
}

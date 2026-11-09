<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A sign-up that has not confirmed its email code yet. No User exists until it does. */
class PendingRegistration extends Model
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    protected $fillable = ['email', 'payload', 'code_hash', 'attempts', 'expires_at', 'last_sent_at'];

    protected $hidden = ['payload', 'code_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    /** Sets a fresh 6-digit code (resets attempts and expiry) and returns it in plain text for the email. */
    public function newCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->fill(['code_hash' => self::hashCode($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'last_sent_at' => now()])->save();

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hashCode(preg_replace('/\D/', '', $code)));
    }

    public function secondsUntilResend(): int
    {
        return max(0, self::RESEND_SECONDS - (int) $this->last_sent_at->diffInSeconds(now()));
    }
}

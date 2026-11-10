<?php

namespace App\Domain;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Platform settings and counters (key/value rows, cached per request). */
class Settings
{
    public const DEFAULTS = [
        'platformName' => 'PartyRent',
        'requireSupplierApproval' => true,
        'aiEnabled' => true,
        'overdueGraceDays' => 0,
        'maxRentalDays' => 30,
        'supportEmail' => 'support@partyrent.ph',
        'supportPhone' => '(02) 8123 4567',
        'commissionPct' => 5,
        // PartyRent's own collection accounts, shown to customers who pay by transfer (step 1, no gateway)
        'payGcashName' => '',
        'payGcashNumber' => '',
        'payBankName' => '',
        'payBankAccountName' => '',
        'payBankAccountNo' => '',
        'payQrPath' => '',
    ];

    /** Collection accounts customers transfer to; empty entries are left out. */
    public static function payAccounts(): array
    {
        $acc = [];
        if (self::get('payGcashNumber')) {
            $acc[] = ['label' => 'GCash', 'name' => self::get('payGcashName'), 'number' => self::get('payGcashNumber')];
        }
        if (self::get('payBankAccountNo')) {
            $acc[] = ['label' => self::get('payBankName') ?: 'Bank', 'name' => self::get('payBankAccountName'), 'number' => self::get('payBankAccountNo')];
        }

        return $acc;
    }

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = Setting::pluck('value', 'key')->all();
            } catch (\Throwable) {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache = null;
    }

    /** Atomically increment a named counter (request / payment / receipt / complaint). */
    public static function nextSeq(string $name): int
    {
        return DB::transaction(function () use ($name) {
            $key = 'seq.'.$name;
            $row = Setting::whereKey($key)->lockForUpdate()->first();
            $next = ((int) ($row?->value ?? 0)) + 1;
            Setting::updateOrCreate(['key' => $key], ['value' => $next]);
            self::$cache = null;

            return $next;
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

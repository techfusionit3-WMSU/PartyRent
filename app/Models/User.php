<?php

namespace App\Models;

use App\Domain\Settings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements \Illuminate\Contracts\Auth\MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STAFF_ROLES = ['admin', 'superadmin', 'cashier'];

    public const ADMIN_PERMS = [
        'customers.view' => 'View customer accounts',
        'customers.manage' => 'Activate / suspend customers',
        'suppliers.view' => 'View rental partner accounts',
        'suppliers.manage' => 'Activate / suspend rental partners',
        'applications.review' => 'Approve / reject rental partner applications',
        'listings.view' => 'View product listings',
        'listings.manage' => 'Hide / restore listings',
        'categories.manage' => 'Manage categories',
        'transactions.view' => 'View transactions & reservations',
        'payouts.manage' => 'Release & send rental partner payouts',
        'complaints.manage' => 'Handle complaints',
        'reports.view' => 'View reports',
    ];

    public const CASHIER_PERMS = [
        'payments.view' => 'View payments',
        'payments.verify' => 'Verify / reject payments',
        'payments.record' => 'Record offline payments & refunds',
        'receipts.issue' => 'Issue receipts',
        'reports.view' => 'View financial reports',
    ];

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'address', 'city_code', 'address_parts', 'roles', 'status',
        'admin_perms', 'cashier_perms', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'address_parts' => 'array',
            'admin_perms' => 'array',
            'cashier_perms' => 'array',
        ];
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class, 'owner_id');
    }

    public function rentalRequests(): HasMany
    {
        return $this->hasMany(RentalRequest::class, 'customer_id');
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? [], true);
    }

    public function isStaff(): bool
    {
        return (bool) array_intersect(self::STAFF_ROLES, $this->roles ?? []);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function defaultMode(): string
    {
        foreach (['superadmin', 'admin', 'cashier'] as $r) {
            if ($this->hasRole($r)) {
                return $r;
            }
        }

        return 'customer';
    }

    public function isApprovedSupplier(): bool
    {
        return $this->supplier?->status === 'approved';
    }

    /** Role the interface is acting as, given the session's chosen mode. */
    public function activeRole(?string $mode = null): string
    {
        if ($this->isStaff()) {
            return $this->defaultMode();
        }

        return $mode === 'supplier' && $this->isApprovedSupplier() ? 'supplier' : 'customer';
    }

    /** Route name of this user's home page for a mode. */
    public function homeRoute(?string $mode = null): string
    {
        return [
            'customer' => 'home',
            'supplier' => 'supplier.dashboard',
            'admin' => 'admin.dashboard',
            'superadmin' => 'super.dashboard',
            'cashier' => 'cashier.dashboard',
        ][$this->activeRole($mode)];
    }

    /** Staff permission check. Superadmin has every permission. */
    public function hasPerm(string $perm): bool
    {
        if ($this->hasRole('superadmin')) {
            return true;
        }
        if ($this->hasRole('admin')) {
            return in_array($perm, $this->admin_perms ?? Settings::get('permissions.admin', array_keys(self::ADMIN_PERMS)), true);
        }
        if ($this->hasRole('cashier')) {
            return in_array($perm, $this->cashier_perms ?? Settings::get('permissions.cashier', array_keys(self::CASHIER_PERMS)), true);
        }

        return false;
    }

    public function initials(): string
    {
        $parts = array_slice(preg_split('/\s+/', trim($this->name)) ?: [], 0, 2);

        return strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $parts))) ?: '?';
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }
}

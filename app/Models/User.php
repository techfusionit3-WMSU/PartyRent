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

    public const STAFF_ROLES = ['admin', 'superadmin', 'cashier', 'employee'];

    public const ADMIN_PERMS = [
        'customers.view' => 'View customer accounts',
        'customers.manage' => 'Activate / suspend customers',
        'suppliers.view' => 'View supplier accounts',
        'suppliers.manage' => 'Activate / suspend suppliers',
        'applications.review' => 'Approve / reject supplier applications',
        'listings.view' => 'View product listings',
        'listings.manage' => 'Hide / restore listings',
        'categories.manage' => 'Manage categories',
        'transactions.view' => 'View transactions & reservations',
        'payouts.manage' => 'Release & send supplier payouts',
        'payments.verify' => 'Verify customer bank / debit transfers',
        'payments.record' => 'Record payments & refunds PartyRent sends',
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

    /** The supplier shop this user OWNS (any status). Use shop() for the shop they work in. */
    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class, 'owner_id');
    }

    /** Active membership in a shop's team (a deactivated member keeps their customer account). */
    public function staffOf(): HasOne
    {
        return $this->hasOne(ShopStaff::class)->where('status', 'active');
    }

    /** The approved supplier this user works in: their own, or the one that employs them (Auth.supplierOf). */
    public function shop(): ?Supplier
    {
        if ($this->supplier?->status === 'approved') {
            return $this->supplier;
        }
        $s = $this->staffOf?->supplier;

        return $s && $s->status === 'approved' ? $s : null;
    }

    /** Main company role (Auth.orgRole): owner = org_admin; employees desk | cashier | inventory | crew; else null. */
    public function orgRole(): ?string
    {
        return $this->orgRoles()[0] ?? null;
    }

    /** All company roles of this user (roles live inside the company, see ShopStaff). */
    public function orgRoles(): array
    {
        if ($this->supplier?->status === 'approved') {
            return ['org_admin'];
        }

        return $this->shop() ? $this->staffOf->roleList() : [];
    }

    public function isShopOwner(): bool
    {
        return $this->supplier?->status === 'approved';
    }

    /** Company permissions: everything for the owner; role defaults ± the owner's tweaks for an employee. */
    public function orgPerms(): array
    {
        if ($this->isShopOwner()) {
            return array_keys(ShopStaff::ORG_PERM_LIST);
        }

        return $this->shop() ? $this->staffOf->perms() : [];
    }

    /** Company modules this user can open (App\Support\CompanyModules). */
    public function modules(): array
    {
        return \App\Support\CompanyModules::forUser($this);
    }

    public function currentModule(): ?string
    {
        return \App\Support\CompanyModules::current($this);
    }

    /** Works the cashier module of a solo shop / rental company (owner or a member with the Cashier role). */
    public function hasShopCashierModule(): bool
    {
        return in_array('cashier', $this->modules(), true);
    }

    /** Kept for older callers: 'owner' for the account owner, else the organization role. */
    public function shopRole(): ?string
    {
        $r = $this->orgRole();

        return $r === 'org_admin' && $this->supplier?->status === 'approved' ? 'owner' : $r;
    }

    /**
     * Does this customer account work in someone else's shop (cashier / inventory / staff)? (Auth.isEmployee)
     * Employees sign in as customers and switch to Business Mode for that shop.
     */
    public function isEmployee(): bool
    {
        return $this->staffOf !== null && $this->supplier?->status !== 'approved';
    }

    /**
     * Working in a shop's Cashier module right now: Business Mode shows the cashier workspace for that shop only.
     * A business (event company) cashier works in the business workspace instead (event payments there).
     */
    public function isShopCashier(): bool
    {
        return $this->hasShopCashierModule() && $this->currentModule() === 'cashier';
    }

    /** Organization permission check (Auth.orgCan). */
    public function orgCan(string $perm): bool
    {
        return in_array($perm, $this->orgPerms(), true);
    }

    /** May this user open the given supplier route? */
    public function canShop(string $routeName): bool
    {
        return $this->shop() !== null && ShopStaff::permsAllow($this->orgPerms(), $routeName);
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

    /** Can use Supplier mode (owner of an approved shop, or staff of a verified business). */
    public function isApprovedSupplier(): bool
    {
        return $this->shop() !== null;
    }

    /** Role the interface is acting as, given the session's chosen mode. */
    public function activeRole(?string $mode = null): string
    {
        if ($this->isStaff()) {
            return $this->defaultMode();
        }

        if ($mode === 'supplier' && $this->isApprovedSupplier()) {
            return $this->isShopCashier() ? 'cashier' : 'supplier';
        }

        return 'customer';
    }

    /** Route name of this user's home page for a mode. */
    public function homeRoute(?string $mode = null): string
    {
        if ($this->activeRole($mode) === 'supplier' && $this->shop()?->isOrganization()) {
            return 'business.dashboard'; // event companies have their own workspace
        }
        if (in_array($this->activeRole($mode), ['supplier', 'cashier'], true) && ($m = $this->currentModule())) {
            return \App\Support\CompanyModules::get($m)['home'];
        }

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
        if ($this->isShopCashier()) {
            return $this->orgCan($perm);
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

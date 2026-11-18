<?php

namespace App\Domain;

use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Team & Roles (port of Services.orgMembers / addEmployee / updateEmployee).
 * Employees are ordinary customer accounts with a membership in one supplier's team. They sign in as a
 * customer and switch to Business Mode: a Cashier gets the cashier workspace for that shop only; Inventory
 * Staff and Staff get the supplier workspace with their role's permissions.
 * The owner stays the Organization Admin.
 */
class ShopStaffs
{
    /** @return array<int, array{role: string, user: User}> owner first */
    public static function members(Supplier $s): array
    {
        $out = [['role' => 'org_admin', 'user' => $s->owner, 'status' => 'active', 'since' => $s->created_at]];
        foreach ($s->staff()->with('user')->get()->sortBy('created_at') as $m) {
            $out[] = ['role' => $m->role, 'user' => $m->user, 'member' => $m, 'status' => $m->status, 'since' => $m->created_at];
        }

        return $out;
    }

    /**
     * Add an employee by email. An existing PartyRent customer account is linked as it is (they keep their own
     * password); a new email gets a customer account with the temporary password the owner shares.
     */
    public static function add(Supplier $s, User $by, array $data): array
    {
        if (! $by->orgCan('team.manage') || $by->shop()?->id !== $s->id) {
            return ['ok' => false, 'error' => 'Only the Organization Admin can add employees.'];
        }
        $role = $data['role'] ?? '';
        if (! in_array($role, ShopStaff::EMPLOYEE_ROLES, true)) {
            return ['ok' => false, 'error' => 'Choose Cashier, Inventory Staff or Staff. Organization Admin rights stay with the account owner.'];
        }
        $email = Str::lower(trim((string) ($data['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $m = ShopStaff::where('user_id', $existing->id)->first();
            if ($m && $m->supplier_id === $s->id) {
                return ['ok' => false, 'error' => $existing->name.' is already on your team.'.($m->status !== 'active' ? ' Reactivate them from the list.' : '')];
            }
            if ($existing->isStaff()) {
                return ['ok' => false, 'error' => 'That email belongs to a PartyRent staff account and cannot join a shop.'];
            }
            if ($existing->supplier || $m) {
                return ['ok' => false, 'error' => 'That account already '.($existing->supplier ? 'has its own supplier profile' : 'works for another shop').'. One account can only work for one shop.'];
            }
            if (! $existing->isActive()) {
                return ['ok' => false, 'error' => 'That account is '.$existing->status.' and cannot be added.'];
            }
        } else {
            if (mb_strlen(trim((string) ($data['name'] ?? ''))) < 3) {
                return ['ok' => false, 'error' => 'No PartyRent account uses that email yet. Enter the employee\'s full name to create one.'];
            }
            if (strlen((string) ($data['password'] ?? '')) < 8) {
                return ['ok' => false, 'error' => 'The temporary password needs at least 8 characters.'];
            }
        }
        $u = DB::transaction(function () use ($s, $by, $data, $email, $role, $existing) {
            $u = $existing;
            if (! $u) {
                $u = User::create([
                    'name' => trim($data['name']), 'email' => $email, 'phone' => trim((string) ($data['phone'] ?? '')),
                    'address' => $s->address, 'password' => Hash::make($data['password']),
                    'roles' => ['customer'], 'status' => 'active',
                ]);
                $u->forceFill(['email_verified_at' => now()])->save(); // the owner vouches for the address
            }
            ShopStaff::create(['supplier_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'added_by' => $by->id]);

            return $u;
        });
        if ($existing) {
            Notifier::notify($u, 'customer', 'You joined '.$s->name, $by->name.' added you as '.ShopStaff::ORG_ROLES[$role].'. Open your account menu and choose "Switch to Business Mode" to work for the shop.', route('home'));
        }
        Notifier::audit('Added employee', $s->name.' · '.$u->name.' ('.ShopStaff::ORG_ROLES[$role].')', $by);

        return ['ok' => true, 'user' => $u->fresh(), 'created' => ! $existing];
    }

    /** patch: role | status (active|deactivated). Deactivating ends their access to the shop, not their own account. */
    public static function update(Supplier $s, User $by, User $u, array $patch): array
    {
        if (! $by->orgCan('team.manage') || $by->shop()?->id !== $s->id) {
            return ['ok' => false, 'error' => 'Only the Organization Admin can manage employees.'];
        }
        $m = ShopStaff::where('supplier_id', $s->id)->where('user_id', $u->id)->first();
        if (! $m || $u->id === $s->owner_id) {
            return ['ok' => false, 'error' => 'You can only manage your own organization\'s employees.'];
        }
        $changes = [];
        if (! empty($patch['role'])) {
            if (! in_array($patch['role'], ShopStaff::EMPLOYEE_ROLES, true)) {
                return ['ok' => false, 'error' => 'Invalid role.'];
            }
            $m->role = $patch['role'];
            $changes[] = 'role: '.$patch['role'];
        }
        if (! empty($patch['status'])) {
            if (! in_array($patch['status'], ['active', 'deactivated'], true)) {
                return ['ok' => false, 'error' => 'Invalid status.'];
            }
            $m->status = $patch['status'];
            $changes[] = 'status: '.$patch['status'];
        }
        $m->save();
        Notifier::audit('Updated employee', $s->name.' · '.$u->name.' · '.implode(', ', $changes), $by);

        return ['ok' => true, 'user' => $u, 'member' => $m];
    }
}

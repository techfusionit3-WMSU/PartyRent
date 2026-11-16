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
 * Employees are separate logins tied to one supplier. A Cashier signs in to the cashier workspace for that
 * shop only; Inventory Staff and Staff sign in to the supplier workspace with their role's permissions.
 * The owner stays the Organization Admin.
 */
class ShopStaffs
{
    /** @return array<int, array{role: string, user: User}> owner first */
    public static function members(Supplier $s): array
    {
        $out = [['role' => 'org_admin', 'user' => $s->owner]];
        foreach ($s->staff()->with('user')->get()->sortBy(fn ($m) => $m->user->created_at) as $m) {
            $out[] = ['role' => $m->role, 'user' => $m->user, 'member' => $m];
        }

        return $out;
    }

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
        if (User::where('email', $email)->exists()) {
            return ['ok' => false, 'error' => 'That email already has a PartyRent account.'];
        }
        if (mb_strlen(trim((string) ($data['name'] ?? ''))) < 3) {
            return ['ok' => false, 'error' => 'Enter the employee\'s full name.'];
        }
        if (strlen((string) ($data['password'] ?? '')) < 8) {
            return ['ok' => false, 'error' => 'The temporary password needs at least 8 characters.'];
        }
        $u = DB::transaction(function () use ($s, $by, $data, $email, $role) {
            $u = User::create([
                'name' => trim($data['name']), 'email' => $email, 'phone' => trim((string) ($data['phone'] ?? '')),
                'address' => $s->address, 'password' => Hash::make($data['password']),
                'roles' => [$role === 'cashier' ? 'cashier' : 'employee'], 'status' => 'active',
            ]);
            $u->forceFill(['email_verified_at' => now()])->save(); // the owner vouches for the address
            ShopStaff::create(['supplier_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'added_by' => $by->id]);

            return $u;
        });
        Notifier::audit('Added employee', $s->name.' · '.$u->name.' ('.ShopStaff::ORG_ROLES[$role].')', $by);

        return ['ok' => true, 'user' => $u];
    }

    /** patch: role | status (active|deactivated) | password */
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
            $m->update(['role' => $patch['role']]);
            $u->roles = [$patch['role'] === 'cashier' ? 'cashier' : 'employee'];
            $changes[] = 'role: '.$patch['role'];
        }
        if (! empty($patch['status'])) {
            if (! in_array($patch['status'], ['active', 'deactivated'], true)) {
                return ['ok' => false, 'error' => 'Invalid status.'];
            }
            $u->status = $patch['status'];
            $changes[] = 'status: '.$patch['status'];
        }
        if (! empty($patch['password'])) {
            if (strlen($patch['password']) < 8) {
                return ['ok' => false, 'error' => 'Password needs at least 8 characters.'];
            }
            $u->password = Hash::make($patch['password']);
            $changes[] = 'password reset';
        }
        $u->save();
        Notifier::audit('Updated employee', $s->name.' · '.$u->name.' · '.implode(', ', $changes), $by);

        return ['ok' => true, 'user' => $u];
    }
}

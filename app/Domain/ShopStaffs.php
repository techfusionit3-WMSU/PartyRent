<?php

namespace App\Domain;

use App\Models\ShopStaff;
use App\Models\StaffInvite;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\StaffInviteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Team & Roles (port of Services.orgMembers / addEmployee / updateEmployee).
 * Employees are ordinary customer accounts with a membership in one supplier's team. They sign in as a
 * customer and switch to Business Mode: a Cashier gets the cashier workspace for that shop only; Inventory
 * Staff and Staff get the supplier workspace with their role's permissions.
 * The owner stays the Organization Admin.
 *
 * Nobody is added directly: the owner invites an email and the person confirms from that inbox. An existing
 * account accepts while signed in; a new email enters the emailed code and sets their own password.
 * One ACTIVE membership per account; someone deactivated elsewhere is free to join.
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

    /** Masked name for the email check, so the form cannot be used to look people up ("Juan Dela Cruz" → "J*** C."). */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';

        return mb_substr($first, 0, 1).'***'.($last !== '' ? ' '.mb_substr($last, 0, 1).'.' : '');
    }

    /** Why this account cannot join shop $s (null = it can). */
    public static function blocker(User $u, Supplier $s): ?string
    {
        if ($u->isStaff()) {
            return 'That email belongs to a PartyRent staff account and cannot join a shop.';
        }
        if ($u->supplier) {
            return 'That account already has its own supplier profile. One account can only work for one business.';
        }
        $active = ShopStaff::where('user_id', $u->id)->where('status', 'active')->first();
        if ($active && $active->supplier_id !== $s->id) {
            return 'That account already works for another business. They need to be removed there first.';
        }
        if ($active) {
            return $u->name.' is already on your team.';
        }
        if (! $u->isActive()) {
            return 'That account is '.$u->status.' and cannot be added.';
        }

        return null;
    }

    /**
     * What happens if the owner invites this email (the live check in the Add employee form).
     * state: existing | new (can invite) · blocked | invited (cannot)
     */
    public static function check(Supplier $s, string $email): array
    {
        $email = Str::lower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['state' => 'blocked', 'message' => 'Enter a valid email address.'];
        }
        if (StaffInvite::where('supplier_id', $s->id)->where('email', $email)->exists()) {
            return ['state' => 'invited', 'message' => 'You already invited this email. Use Resend in the team list.'];
        }
        $u = User::where('email', $email)->first();
        if (! $u) {
            return ['state' => 'new', 'message' => 'No PartyRent account uses this email yet. We will email them a code so they can create one and set their own password.'];
        }
        if (ShopStaff::where('supplier_id', $s->id)->where('user_id', $u->id)->where('status', '!=', 'active')->exists()) {
            return ['state' => 'blocked', 'message' => $u->name.' was on your team before. Reactivate them from the team list instead.'];
        }
        if ($why = self::blocker($u, $s)) {
            return ['state' => 'blocked', 'message' => $why];
        }

        return ['state' => 'existing', 'message' => self::maskName($u->name).' has a PartyRent account. They will be asked to accept your invitation.'];
    }

    /** Invite an email to the team. Nobody joins until the person confirms from that inbox. */
    public static function invite(Supplier $s, User $by, array $data): array
    {
        if (! $by->orgCan('team.manage') || $by->shop()?->id !== $s->id) {
            return ['ok' => false, 'error' => 'Only the Organization Admin can add employees.'];
        }
        $role = $data['role'] ?? '';
        if (! in_array($role, ShopStaff::EMPLOYEE_ROLES, true)) {
            return ['ok' => false, 'error' => 'Choose Cashier, Inventory Staff or Staff. Organization Admin rights stay with the account owner.'];
        }
        $email = Str::lower(trim((string) ($data['email'] ?? '')));
        $chk = self::check($s, $email);
        if (! in_array($chk['state'], ['existing', 'new'], true)) {
            return ['ok' => false, 'error' => $chk['message']];
        }
        $inv = new StaffInvite(['supplier_id' => $s->id, 'email' => $email, 'role' => $role, 'invited_by' => $by->id]);
        $sent = self::send($inv);
        Notifier::audit('Invited employee', $s->name.' · '.$email.' ('.ShopStaff::ORG_ROLES[$role].')', $by);

        return ['ok' => true, 'invite' => $inv, 'existing' => $chk['state'] === 'existing', 'sent' => $sent];
    }

    /** (Re)issue the link and code and email them; an existing account also gets an in-app notification. */
    public static function send(StaffInvite $inv): bool
    {
        [$token, $code] = $inv->issue();
        $u = User::where('email', $inv->email)->first();
        if ($u) {
            Notifier::notify($u, 'customer', 'Invitation to join '.$inv->supplier->name, ($inv->inviter?->name ?? $inv->supplier->name).' invited you to work as '.$inv->roleLabel().'. Open it to accept or decline.', route('invite.show', $token, false));
        }
        try {
            Notification::route('mail', $inv->email)->notify(new StaffInviteMail($inv, route('invite.show', $token), $u ? null : $code));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function resend(Supplier $s, User $by, StaffInvite $inv): array
    {
        if (! $by->orgCan('team.manage') || $inv->supplier_id !== $s->id) {
            return ['ok' => false, 'error' => 'You can only manage your own invitations.'];
        }
        if (($w = $inv->secondsUntilResend()) > 0) {
            return ['ok' => false, 'error' => 'Please wait '.$w.' seconds before sending again.'];
        }

        return ['ok' => true, 'sent' => self::send($inv)];
    }

    public static function cancel(Supplier $s, User $by, StaffInvite $inv): array
    {
        if (! $by->orgCan('team.manage') || $inv->supplier_id !== $s->id) {
            return ['ok' => false, 'error' => 'You can only manage your own invitations.'];
        }
        $inv->delete();
        Notifier::audit('Cancelled invitation', $s->name.' · '.$inv->email, $by);

        return ['ok' => true];
    }

    /** The invited person (signed in with the invited email) joins the team. The rules are checked again here. */
    public static function accept(StaffInvite $inv, User $u): array
    {
        if (Str::lower($u->email) !== $inv->email) {
            return ['ok' => false, 'error' => 'This invitation is for '.$inv->email.'.'];
        }
        if ($inv->isExpired()) {
            return ['ok' => false, 'error' => 'This invitation has expired. Ask the shop to send it again.'];
        }
        $s = $inv->supplier;
        if ($why = self::blocker($u, $s)) {
            return ['ok' => false, 'error' => str_replace('That account', 'Your account', $why)];
        }
        DB::transaction(function () use ($inv, $u, $s) {
            ShopStaff::updateOrCreate(['supplier_id' => $s->id, 'user_id' => $u->id], ['role' => $inv->role, 'status' => 'active', 'added_by' => $inv->invited_by]);
            StaffInvite::where('email', $inv->email)->delete(); // other shops' invitations no longer apply
        });
        Notifier::notify($s->owner, 'supplier', $u->name.' joined your team', $u->name.' accepted your invitation and is now '.$inv->roleLabel().'.', route('supplier.team', [], false));
        Notifier::audit('Joined team', $s->name.' · '.$u->name.' ('.$inv->roleLabel().')', $u);

        return ['ok' => true, 'supplier' => $s, 'role' => $inv->roleLabel()];
    }

    public static function decline(StaffInvite $inv, User $u): array
    {
        if (Str::lower($u->email) !== $inv->email) {
            return ['ok' => false, 'error' => 'This invitation is for '.$inv->email.'.'];
        }
        $s = $inv->supplier;
        $inv->delete();
        Notifier::notify($s->owner, 'supplier', $u->name.' declined your invitation', $u->name.' will not join '.$s->name.'.', route('supplier.team', [], false));

        return ['ok' => true, 'supplier' => $s];
    }

    /** A new email: checks the code, creates the account with the person's own password, then joins. */
    public static function joinNew(StaffInvite $inv, array $data): array
    {
        if ($inv->isExpired()) {
            return ['ok' => false, 'error' => 'This invitation has expired. Ask the shop to send it again.'];
        }
        if ($inv->attempts >= StaffInvite::MAX_ATTEMPTS) {
            return ['ok' => false, 'field' => 'code', 'error' => 'Too many wrong codes. Ask the shop to send the invitation again.'];
        }
        if (! $inv->matches((string) ($data['code'] ?? ''))) {
            $inv->increment('attempts');
            $left = StaffInvite::MAX_ATTEMPTS - $inv->attempts;

            return ['ok' => false, 'field' => 'code', 'error' => $left > 0 ? 'That code is not right. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.' : 'Too many wrong codes. Ask the shop to send the invitation again.'];
        }
        if (User::where('email', $inv->email)->exists()) {
            return ['ok' => false, 'error' => 'This email now has a PartyRent account. Log in to accept the invitation.'];
        }
        $s = $inv->supplier;
        $u = DB::transaction(function () use ($inv, $data, $s) {
            $u = User::create([
                'name' => trim($data['name']), 'email' => $inv->email, 'phone' => trim((string) $data['phone']),
                'address' => $s->address, 'password' => Hash::make($data['password']),
                'roles' => ['customer'], 'status' => 'active',
            ]);
            $u->forceFill(['email_verified_at' => now()])->save(); // the code proves they own the inbox

            return $u;
        });
        Notifier::notify($u, 'customer', 'Welcome to PartyRent! 🎉', 'You can rent party supplies too. To work for '.$s->name.', choose "Switch to Business Mode".', route('home', [], false));

        return self::accept($inv, $u) + ['user' => $u];
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
            // they may have joined another business (or opened their own shop) since being deactivated here
            if ($patch['status'] === 'active' && $m->status !== 'active' && ($why = self::blocker($u, $s))) {
                return ['ok' => false, 'error' => 'Cannot reactivate '.$u->name.': '.lcfirst(str_replace('That account', 'their account', $why))];
            }
            $m->status = $patch['status'];
            $changes[] = 'status: '.$patch['status'];
        }
        $m->save();
        Notifier::audit('Updated employee', $s->name.' · '.$u->name.' · '.implode(', ', $changes), $by);

        return ['ok' => true, 'user' => $u, 'member' => $m];
    }
}

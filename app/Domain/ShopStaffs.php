<?php

namespace App\Domain;

use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Staff accounts for Business rental partners.
 * The owner adds people by email. An existing PartyRent account is linked; otherwise a new account is
 * made and the person gets a "set your password" email. A person works for one shop at a time.
 */
class ShopStaffs
{
    public static function add(Supplier $s, User $by, string $name, string $email, string $role): array
    {
        if (! $s->isVerifiedBusiness()) {
            return ['ok' => false, 'error' => 'Staff accounts are for verified Business accounts.'];
        }
        if (! isset(ShopStaff::ROLES[$role])) {
            return ['ok' => false, 'error' => 'Choose a role.'];
        }
        $email = Str::lower(trim($email));
        $u = User::where('email', $email)->first();
        if ($u) {
            if ($u->id === $s->owner_id) {
                return ['ok' => false, 'error' => 'That is your own account. As the owner you can already do everything.'];
            }
            if ($u->isStaff()) {
                return ['ok' => false, 'error' => 'That email belongs to a PartyRent staff account and cannot be added to a shop.'];
            }
            if ($u->supplier && $u->supplier->status !== 'rejected') {
                return ['ok' => false, 'error' => 'That person has their own rental partner shop, so they cannot also be your staff.'];
            }
            if ($u->staffOf) {
                return ['ok' => false, 'error' => $u->staffOf->supplier_id === $s->id ? 'That person is already on your staff.' : 'That person already works for another shop.'];
            }
            $new = false;
        } else {
            $u = User::create([
                'name' => trim($name) ?: Str::before($email, '@'), 'email' => $email, 'password' => Hash::make(Str::random(40)),
                'roles' => ['customer'], 'status' => 'active',
            ]);
            // The owner vouches for the address, and the password link proves it, so no extra verify step.
            $u->forceFill(['email_verified_at' => now()])->save();
            $new = true;
        }
        ShopStaff::create(['supplier_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'added_by' => $by->id]);
        $label = ShopStaff::ROLES[$role][0];
        if ($new) {
            Password::sendResetLink(['email' => $u->email]);
        }
        Notifier::notify($u, 'customer', 'You were added to '.$s->name, $s->name.' added you as '.$label.'. Open your profile menu and choose "Work at '.$s->name.'".', '');
        Notifier::audit('Added shop staff', $s->name.' · '.$u->email.' as '.$label, $by);

        return ['ok' => true, 'user' => $u, 'new' => $new];
    }

    public static function changeRole(ShopStaff $m, User $by, string $role): array
    {
        if (! isset(ShopStaff::ROLES[$role])) {
            return ['ok' => false, 'error' => 'Choose a role.'];
        }
        $m->update(['role' => $role]);
        Notifier::notify($m->user_id, 'customer', 'Your role changed', 'You are now '.ShopStaff::ROLES[$role][0].' at '.$m->supplier->name.'.', '');
        Notifier::audit('Changed shop staff role', $m->supplier->name.' · '.$m->user->email.' → '.ShopStaff::ROLES[$role][0], $by);

        return ['ok' => true];
    }

    /** Removing only unlinks the person; their PartyRent account stays. */
    public static function remove(ShopStaff $m, User $by): array
    {
        $shop = $m->supplier->name;
        $m->delete();
        Notifier::notify($m->user_id, 'customer', 'Removed from '.$shop, 'You no longer have access to '.$shop.'.', '');
        Notifier::audit('Removed shop staff', $shop.' · '.$m->user->email, $by);

        return ['ok' => true];
    }
}

<?php

namespace App\Domain;

use App\Models\CustomerVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Customer verification before renting: a government ID and a payment account (proved with a screenshot of the
 * account's profile / statement header: PartyRent never asks for passwords, PINs or OTPs) are required; proof of
 * insurance is optional. PartyRent admins review it. Until it is approved the customer cannot check out.
 * Files are kept on the private disk and only sent to the customer and to admins (self::send).
 */
class Verifications
{
    public const DISK = 'local';

    /** Is verification needed to rent? (Setting verification.required, on by default.) */
    public static function required(): bool
    {
        return (bool) Settings::get('verification.required', true);
    }

    public static function of(User $u): CustomerVerification
    {
        return CustomerVerification::firstOrNew(['user_id' => $u->id], ['status' => 'not_submitted', 'insurance_status' => 'none']);
    }

    /** Why this customer can't rent yet, or null when they can. */
    public static function blocker(User $u): ?string
    {
        if (! self::required()) {
            return null;
        }
        $v = self::of($u);

        return match ($v->status) {
            'approved' => null,
            'pending' => 'Your account verification is being reviewed. You can check out once PartyRent approves it (usually within 1 business day).',
            'rejected', 'resubmit' => 'Your account verification needs attention: '.($v->reason ?: 'please send it again').'. Fix it on the Verification page, then check out.',
            default => 'Verify your account before renting: add a government ID and your bank or e-wallet account. It takes about 2 minutes.',
        };
    }

    private static function store(UploadedFile $f, User $u, string $what): string
    {
        return $f->storeAs('verifications/'.$u->id, $what.'-'.bin2hex(random_bytes(8)).'.'.strtolower($f->extension() ?: 'jpg'), self::DISK);
    }

    private static function drop(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** The customer sends (or sends again) their ID and payment account; optional insurance. $data is validated. */
    public static function submit(User $u, array $data, array $files): CustomerVerification
    {
        return DB::transaction(function () use ($u, $data, $files) {
            $v = self::of($u);
            $old = [];
            foreach (['id_front' => 'id_front_path', 'id_back' => 'id_back_path', 'pay_proof' => 'pay_proof_path'] as $in => $col) {
                if (isset($files[$in])) {
                    $old[] = $v->{$col};
                    $v->{$col} = self::store($files[$in], $u, $in);
                }
            }
            $v->fill([
                'id_type' => $data['id_type'], 'id_last4' => $data['id_last4'],
                'pay_provider' => $data['pay_provider'], 'pay_account_name' => trim($data['pay_account_name']), 'pay_last4' => $data['pay_last4'],
                'status' => 'pending', 'reason' => null, 'submitted_at' => now(), 'id_verified_at' => null, 'pay_verified_at' => null,
            ]);
            if (isset($files['insurance'])) {
                $old[] = $v->insurance_path;
                $v->insurance_path = self::store($files['insurance'], $u, 'insurance');
                $v->fill(['insurance_status' => 'submitted', 'insurer' => $data['insurer'] ?? null, 'insurance_expires' => $data['insurance_expires'] ?? null]);
            }
            $v->log('Sent for review');
            $v->save();
            DB::afterCommit(fn () => array_map([self::class, 'drop'], array_filter($old)));
            Notifier::notify('admin', 'admin', 'Customer verification to review', $u->name.' sent their ID and payment account for verification.', route('admin.verifications', ['id' => $v->id], false));

            return $v;
        });
    }

    /** Add or replace the optional proof of insurance (any time). */
    public static function insurance(User $u, UploadedFile $f, ?string $insurer, ?string $expires): CustomerVerification
    {
        $v = self::of($u);
        $old = $v->insurance_path;
        $v->fill(['insurance_path' => self::store($f, $u, 'insurance'), 'insurance_status' => 'submitted', 'insurer' => $insurer, 'insurance_expires' => $expires]);
        $v->log('Sent proof of insurance');
        $v->save();
        self::drop($old);
        Notifier::notify('admin', 'admin', 'Proof of insurance to review', $u->name.' sent proof of insurance.', route('admin.verifications', ['id' => $v->id], false));

        return $v;
    }

    /**
     * Admin decision: approve | reject | resubmit (with a reason), and the insurance (verify | reject | keep).
     */
    public static function decide(CustomerVerification $v, string $action, ?string $reason, ?string $insurance, User $by): array
    {
        if (in_array($action, ['reject', 'resubmit'], true) && mb_strlen(trim((string) $reason)) < 5) {
            return ['ok' => false, 'error' => 'Tell the customer why (at least a few words), so they know what to fix.'];
        }
        if ($action !== 'insurance' && $v->status !== 'pending') {
            return ['ok' => false, 'error' => 'This verification is not waiting for review.'];
        }
        DB::transaction(function () use ($v, $action, $reason, $insurance, $by) {
            if ($action === 'approve') {
                $v->fill(['status' => 'approved', 'reason' => null, 'id_verified_at' => now(), 'pay_verified_at' => now()]);
                $v->log('Approved', $by);
            } elseif ($action !== 'insurance') {
                $v->fill(['status' => $action === 'reject' ? 'rejected' : 'resubmit', 'reason' => mb_substr(trim($reason), 0, 500), 'id_verified_at' => null, 'pay_verified_at' => null]);
                $v->log(($action === 'reject' ? 'Rejected: ' : 'Asked to send again: ').trim($reason), $by);
            }
            if (in_array($insurance, ['verify', 'reject'], true) && $v->insurance_path) {
                $v->insurance_status = $insurance === 'verify' ? 'verified' : 'rejected';
                $v->log($insurance === 'verify' ? 'Insurance verified' : 'Insurance not accepted', $by);
            }
            $v->fill(['reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
        });
        $title = match ($action) { 'approve' => 'Your account is verified ✅', 'reject' => 'Account verification rejected', 'resubmit' => 'Please send your verification again', default => 'Proof of insurance reviewed' };
        $body = match ($action) {
            'approve' => 'You can now rent from any shop on PartyRent.',
            'insurance' => 'Your proof of insurance was '.($v->insurance_status === 'verified' ? 'verified.' : 'not accepted.'),
            default => 'Reason: '.trim($reason).'. Open the Verification page to fix it.',
        };
        Notifier::notify($v->user_id, 'customer', $title, $body, route('account.verification', [], false));
        Notifier::audit('Customer verification: '.$action, $v->user->name.($reason ? ' · '.$reason : ''), $by);

        return ['ok' => true];
    }

    /** Send one uploaded file to the customer who owns it or to an admin. */
    public static function send(CustomerVerification $v, string $which, User $viewer)
    {
        abort_unless($viewer->id === $v->user_id || $viewer->hasPerm('customers.view'), 403);
        $col = ['id_front' => 'id_front_path', 'id_back' => 'id_back_path', 'pay_proof' => 'pay_proof_path', 'insurance' => 'insurance_path'][$which] ?? null;
        $path = $col ? $v->{$col} : null;
        abort_unless($path && Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

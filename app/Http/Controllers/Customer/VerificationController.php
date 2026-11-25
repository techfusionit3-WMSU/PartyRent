<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Verifications;
use App\Http\Controllers\Controller;
use App\Models\CustomerVerification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The customer's own verification page: government ID + payment account (required), insurance (optional). */
class VerificationController extends Controller
{
    private const FILE = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';

    public function show(Request $request)
    {
        return view('customer.verification', ['v' => Verifications::of($request->user()), 'required' => Verifications::required()]);
    }

    public function submit(Request $request)
    {
        $v = Verifications::of($request->user());
        if (! $v->canSubmit()) {
            return back()->with('error', $v->status === 'pending' ? 'Your verification is already being reviewed.' : 'Your account is already verified.');
        }
        $first = ! $v->exists || ! $v->id_front_path;
        $data = $request->validate([
            'id_type' => ['required', Rule::in(array_keys(CustomerVerification::ID_TYPES))],
            'id_last4' => ['required', 'regex:/^[A-Za-z0-9]{4}$/'],
            'id_front' => [$first ? 'required' : 'nullable', ...explode('|', self::FILE)],
            'id_back' => ['nullable', ...explode('|', self::FILE)],
            'pay_provider' => ['required', Rule::in(CustomerVerification::PAY_PROVIDERS)],
            'pay_account_name' => ['required', 'string', 'min:2', 'max:120'],
            'pay_last4' => ['required', 'digits:4'],
            'pay_proof' => [$first || ! $v->pay_proof_path ? 'required' : 'nullable', ...explode('|', self::FILE)],
            'insurance' => ['nullable', ...explode('|', self::FILE)],
            'insurer' => ['nullable', 'string', 'max:80'],
            'insurance_expires' => ['nullable', 'date', 'after:today'],
            'consent' => 'accepted',
        ], [
            'id_last4.regex' => 'Enter the last 4 characters of the ID number.', 'id_front.required' => 'Add a photo of the front of your ID.',
            'pay_last4.digits' => 'Enter the last 4 digits of the account or mobile number.', 'pay_proof.required' => 'Add a screenshot of your account (profile page or statement header showing your name).',
            'consent.accepted' => 'Please agree so PartyRent can review your documents.', '*.mimes' => 'Use a JPG, PNG, WEBP or PDF file.', '*.max' => 'Files can be up to 5 MB.',
        ]);
        Verifications::submit($request->user(), $data, array_filter($request->only(['id_front', 'id_back', 'pay_proof', 'insurance'])));

        return redirect()->route('account.verification')->with('success', 'Sent for review. PartyRent usually reviews it within 1 business day; you will get a notification.');
    }

    public function insurance(Request $request)
    {
        $data = $request->validate(['insurance' => ['required', ...explode('|', self::FILE)], 'insurer' => 'nullable|string|max:80', 'insurance_expires' => 'nullable|date|after:today']);
        Verifications::insurance($request->user(), $request->file('insurance'), $data['insurer'] ?? null, $data['insurance_expires'] ?? null);

        return redirect()->route('account.verification')->with('success', 'Proof of insurance sent for review.');
    }

    public function file(Request $request, string $which)
    {
        $v = CustomerVerification::where('user_id', $request->user()->id)->firstOrFail();

        return Verifications::send($v, $which, $request->user());
    }
}

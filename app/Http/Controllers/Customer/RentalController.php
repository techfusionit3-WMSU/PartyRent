<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance;
use App\Domain\OnlinePay;
use App\Domain\Rentals;
use App\Domain\Reviews;
use App\Http\Controllers\Controller;
use App\Models\PayAccount;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Review;
use Illuminate\Http\Request;

class RentalController extends Controller
{
    private function own(Request $request, RentalRequest $r): RentalRequest
    {
        abort_unless($r->customer_id === $request->user()->id, 404);
        $r->load(['payments', 'supplier']);

        return $r;
    }

    public function index(Request $request)
    {
        $all = RentalRequest::where('customer_id', $request->user()->id)->with(['payments', 'supplier'])->latest()->get();
        $groups = [
            'all' => fn ($r) => true, 'pending' => fn ($r) => $r->status === 'pending', 'active' => fn ($r) => $r->isActive(),
            'completed' => fn ($r) => $r->status === 'completed', 'closed' => fn ($r) => in_array($r->status, ['declined', 'cancelled'], true),
        ];
        $tab = array_key_exists($request->query('tab'), $groups) ? $request->query('tab') : 'all';
        $q = mb_strtolower((string) $request->query('q', ''));
        $list = $all->filter($groups[$tab])->filter(fn ($r) => ! $q || str_contains(mb_strtolower($r->code.' '.$r->event_name.' '.$r->supplier->name), $q));

        return view('customer.rentals', ['list' => $list, 'tab' => $tab, 'q' => $request->query('q', ''), 'counts' => array_map(fn ($fn) => $all->filter($fn)->count(), $groups)]);
    }

    public function show(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $f = $r->fin();
        $types = [];
        if ($r->isActive()) {
            $sug = Finance::suggestedNext($r);
            $left = r2($f['balance'] - $f['pendingRental']);
            if ($left > 0.009) {
                $types[] = [$sug['type'], $sug['label'].' (suggested)', $sug['amount'] ?: $left];
                if ($sug['amount'] < $left - 0.009) {
                    $types[] = ['balance', 'Full remaining balance', $left];
                }
            }
            if ($f['depositPaid'] < $f['depositRequired']) {
                $types[] = ['deposit', 'Security deposit (refundable)', r2($f['depositRequired'] - $f['depositPaid'])];
            }
            if ($f['compensationDue'] > 0) {
                $types[] = ['compensation', 'Damage compensation', $f['compensationDue']];
            }
        }
        $chat = ['rows' => \App\Domain\Chat::rows($r, 'customer'), 'unread' => \App\Domain\Chat::unread($r, 'customer')];
        \App\Domain\Chat::markSeen($r, 'customer');

        return view('customer.rental', [
            'r' => $r, 'chat' => $chat, 'f' => $f, 'sup' => $r->supplier, 'payTypes' => $types,
            'online' => OnlinePay::enabled(), 'accounts' => PayAccount::available(),
            'policy' => Rentals::cancelPolicy($r), 'reviewables' => $r->status === 'completed' ? Reviews::reviewables($r) : [],
        ]);
    }


    /** Chat with the shop: new messages as JSON (polling), or post one (JSON from the page, form fallback). */
    public function messages(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $rows = \App\Domain\Chat::rows($r, 'customer', (int) $request->query('after', 0));
        if ($rows) {
            \App\Domain\Chat::markSeen($r, 'customer');
        }

        return response()->json(['ok' => true, 'messages' => $rows]);
    }

    public function sendMessage(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = \App\Domain\Chat::post($r, $request->user(), 'customer', (string) $request->input('body', ''));
        if ($request->expectsJson()) {
            return response()->json($res['ok'] ? ['ok' => true, 'messages' => \App\Domain\Chat::rows($r, 'customer', (int) $request->input('after', 0))] : $res, $res['ok'] ? 200 : 422);
        }

        return redirect()->to(url()->previous().'#chat')->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Message sent.' : $res['error']);
    }

    public function confirmation(Request $request, RentalRequest $rentalRequest)
    {
        return redirect()->route('rentals.submitted', ['ids' => $this->own($request, $rentalRequest)->code]);
    }

    /** Customer proposes (or re-proposes) an installment schedule. */
    public function proposeInstallments(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $items = array_values(array_filter((array) $request->input('items', []), fn ($x) => ! empty($x['due']) && (float) ($x['amount'] ?? 0) > 0));
        $res = Rentals::proposeInstallmentPlan($r, $request->user(), $items);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Schedule sent to '.$r->supplier->name.' for approval.' : $res['error']);
    }

    public function cancel(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = Rentals::cancelRequest($r, $data['reason']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Request cancelled. The supplier has been notified.' : $res['error']);
    }

    /** Customer payment submission — pending until a cashier verifies it. */
    public function pay(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate([
            'type' => 'required|in:reservation,down,installment,balance,full,deposit,compensation',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cod,bank,debit,online',
            'reference' => 'nullable|string|max:80',
            'date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:255',
            'proof' => 'nullable|image|max:8192',
            'pay_account' => 'nullable|integer',
        ]);
        $data['pay_account_id'] = $data['pay_account'] ?? null;
        unset($data['pay_account']);
        if ($data['method'] === 'online') {
            $res = OnlinePay::start($r, array_intersect_key($data, array_flip(['type', 'amount', 'notes'])));

            return $res['ok'] ? redirect()->away($res['url']) : back()->withInput()->with('error', $res['error']);
        }
        if (! in_array($data['method'], $r->supplier->methods, true)) {
            return back()->with('error', 'This supplier does not accept that payment method.');
        }
        $bank = in_array($data['method'], ['bank', 'debit'], true);
        if ($bank && ! $request->hasFile('proof')) {
            return back()->withInput()->with('warn', 'Please upload your payment proof for bank/debit payments.');
        }
        if ($request->hasFile('proof')) {
            // Proofs are stored privately and only served to authorized roles.
            $data['proof_path'] = $request->file('proof')->store('proofs', 'local');
            $data['proof_name'] = mb_substr($request->file('proof')->getClientOriginalName(), 0, 120);
        }
        $res = Rentals::submitPayment($r, $data, 'customer');
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return back()->with('success', 'Payment submitted ('.$res['payment']->code.'). Waiting for cashier verification.');
    }

    /** Back from the gateway's checkout page (or "Check status"): ask the gateway whether it was paid. */
    public function onlineReturn(Request $request, RentalRequest $rentalRequest, Payment $payment)
    {
        $r = $this->own($request, $rentalRequest);
        abort_unless($payment->rental_request_id === $r->id && $payment->gateway !== null, 404);
        $state = OnlinePay::sync($payment);
        $back = redirect()->route('rentals.show', $r);
        if ($state === 'unpaid' && $request->boolean('cancelled')) {
            OnlinePay::discard($payment);

            return $back->with('warn', 'Online payment cancelled. Nothing was charged.');
        }
        $payment->refresh();

        return match (true) {
            $payment->status === 'verified' => $back->with('success', 'Payment of '.money($payment->amount).' received and verified. Receipt '.$payment->receipt_no.'.'),
            $payment->status === 'pending' => $back->with('success', 'Payment received. Our cashier will confirm it shortly.'),
            $state === 'error' => $back->with('warn', 'We could not check with '.OnlinePay::name($payment->gateway).' just now. If you paid, it will update shortly. Use "Check status" to try again.'),
            default => $back->with('warn', 'We have not received this online payment yet. If you already paid, wait a minute and use "Check status".'),
        };
    }

    public function review(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        abort_unless($r->status === 'completed', 422);
        $request->validate([
            'reviews' => 'required|array',
            'reviews.*.rating' => 'nullable|integer|min:1|max:5',
            'reviews.*.comment' => 'nullable|string|max:1000',
            'reviews.*.photos' => 'nullable|array|max:'.Reviews::MAX_PHOTOS,
            'reviews.*.photos.*' => 'file|mimes:'.Reviews::PHOTO_MIMES.'|max:'.Reviews::MAX_PHOTO_KB,
            'reviews.*.video' => 'nullable|file|mimetypes:'.Reviews::VIDEO_MIMETYPES.'|max:'.Reviews::MAX_VIDEO_KB,
        ], [
            'reviews.*.photos.max' => 'You can add up to '.Reviews::MAX_PHOTOS.' photos per item.',
            'reviews.*.photos.*.mimes' => 'Photos must be JPG, PNG or WEBP.',
            'reviews.*.photos.*.max' => 'Each photo must be 5 MB or smaller.',
            'reviews.*.video.mimetypes' => 'Videos must be MP4, WEBM or MOV.',
            'reviews.*.video.max' => 'The video must be 20 MB or smaller.',
        ]);
        $res = Reviews::submit($r, $request->user(), $request->input('reviews', []), $request->file('reviews', []));
        if (! $res['ok']) {
            return back()->withInput()->withErrors($res['errors'] ?? ['reviews' => $res['error']]);
        }

        return back()->with('success', 'Thanks for your review'.($res['created'] > 1 ? 's' : '').'! It is now visible on the shop and item pages.');
    }

    public function payments(Request $request)
    {
        $reqs = RentalRequest::where('customer_id', $request->user()->id)->with('payments')->get();
        $pays = Payment::whereIn('rental_request_id', $reqs->pluck('id'))->with('rentalRequest')->orderByDesc('submitted_at')->get();
        $f = in_array($request->query('f'), ['pending', 'verified', 'rejected'], true) ? $request->query('f') : 'all';

        return view('customer.payments', [
            'pays' => $f === 'all' ? $pays : $pays->where('status', $f), 'all' => $pays, 'f' => $f,
            'paid' => $pays->where('status', 'verified')->whereIn('type', Finance::RENTAL_TYPES)->sum('amount'),
            'owed' => $reqs->filter->isActive()->sum(fn ($r) => $r->fin()['balance']),
            'activeCount' => $reqs->filter->isActive()->count(),
            'held' => $reqs->sum(fn ($r) => $r->fin()['depositHeld']),
        ]);
    }

    public function profile(Request $request)
    {
        $u = $request->user();
        $reqs = RentalRequest::where('customer_id', $u->id)->get();

        return view('customer.profile', ['u' => $u, 'sup' => $u->supplier, 'reqs' => $reqs, 'welcome' => $request->boolean('welcome')]);
    }
}

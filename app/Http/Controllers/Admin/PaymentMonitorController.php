<?php

namespace App\Http\Controllers\Admin;

use App\Domain\EventOnlinePay;
use App\Domain\Notifier;
use App\Domain\OnlinePay;
use App\Domain\PaymentChecks;
use App\Domain\PayMongo;
use App\Domain\Xendit;
use App\Http\Controllers\Controller;
use App\Models\EventPayment;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * Admin → Online Payments: payment checking is automatic. Shows the gateway set-up, the checkouts still open,
 * what the automatic checks and webhooks did (payment_transactions), and a "Check now" button.
 * Admins only watch here; nothing on this page verifies a payment by hand.
 */
class PaymentMonitorController extends Controller
{
    public const RESULTS = ['all' => 'All', 'paid' => 'Paid', 'review' => 'Needs review', 'expired' => 'Expired', 'rejected' => 'Rejected', 'error' => 'Errors', 'ignored' => 'Ignored'];

    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasPerm('transactions.view'), 403, 'You do not have permission for this page.');
    }

    public function index(Request $request)
    {
        $this->guard($request);
        PaymentChecks::runIfDue();

        $result = array_key_exists($request->query('result'), self::RESULTS) ? $request->query('result') : 'all';
        $log = PaymentTransaction::query()->when($result !== 'all', fn ($q) => $q->where('result', $result))
            ->latest('id')->paginate(25)->withQueryString();

        $today = now()->startOfDay();
        $online = Payment::whereNotNull('gateway');
        $gw = OnlinePay::gateway();

        return view('admin.payment-monitor', [
            'result' => $result, 'log' => $log,
            'open' => Payment::where('status', 'awaiting')->whereNotNull('gateway')->whereIn('type', Payment::INFLOW)->with('rentalRequest.customer')->orderBy('id')->get(),
            'openEvents' => EventPayment::where('status', 'due')->whereNotNull('gateway')->whereNotNull('gateway_ref')->with('booking.customer')->orderBy('id')->get(),
            'review' => Payment::where('status', 'pending')->whereNotNull('gateway')->with('rentalRequest')->get(),
            'stats' => [
                'verifiedToday' => (clone $online)->where('status', 'verified')->where('verified_at', '>=', $today)->count(),
                'collected' => (float) (clone $online)->where('status', 'verified')->whereIn('type', Payment::INFLOW)->sum('amount')
                    + (float) EventPayment::where('status', 'paid')->where('method', 'online')->sum('amount'),
                'fees' => (float) (clone $online)->where('status', 'verified')->sum('gateway_fee'),
                'rejected' => PaymentTransaction::where('result', 'rejected')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'setup' => [
                'gateway' => $gw ? OnlinePay::name($gw) : null,
                'practice' => OnlinePay::practice(),
                'live' => $gw === 'xendit' ? Xendit::liveMode() : ($gw === 'paymongo' ? PayMongo::liveMode() : false),
                'webhook' => match ($gw) {
                    'xendit' => (string) config('services.xendit.callback_token') !== '',
                    'paymongo' => PayMongo::webhookSecret() !== '',
                    default => false,
                },
                'webhookUrl' => $gw === 'xendit' ? route('webhooks.xendit') : route('webhooks.paymongo'),
                'events' => EventOnlinePay::mode(),
            ],
            'last' => PaymentChecks::lastRun(),
        ]);
    }

    public function check(Request $request)
    {
        $this->guard($request);
        $n = PaymentChecks::run('manual_check');
        Notifier::audit('Checked online payments', $n['checked'].' open checkout(s): '.$n['paid'].' paid, '.$n['review'].' to review, '.$n['expired'].' expired', $request->user());

        $msg = $n['checked'] === 0
            ? 'No open online checkouts to check.'
            : 'Checked '.$n['checked'].' open checkout(s): '.$n['paid'].' paid and verified, '.$n['review'].' sent to the cashier, '.$n['expired'].' expired'.($n['error'] ? ', '.$n['error'].' could not be reached' : '').'.';

        return back()->with($n['error'] ? 'warn' : 'success', $msg);
    }
}

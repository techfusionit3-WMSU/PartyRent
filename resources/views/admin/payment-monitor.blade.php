@extends('layouts.dash', ['title' => 'Online Payments', 'nav' => 'online-payments'])

@section('content')
<div class="page-head"><div><h1>Online payments</h1><p>Payment checking is automatic. The gateway confirms each GCash, Maya or card payment, PartyRent checks the notification, and the order updates by itself. Nobody verifies these by hand.</p></div>
  <form method="POST" action="{{ route('admin.payment-monitor.check') }}">@csrf<button class="btn btn-primary btn-sm">{{ icon('refresh') }}Check now</button></form></div>

<div class="card mb-3"><div class="strong mb-1">How a payment moves</div>
  <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">
    @foreach([
      ['1', 'Customer', 'Checks out and pays with GCash, Maya or a card on the gateway\'s page.'],
      ['2', 'Payment gateway', ($setup['gateway'] ?? 'The gateway').' processes the payment and sends PartyRent a webhook.'],
      ['3', 'PartyRent collects', 'The webhook\'s signature is checked first. Then the payment is verified, a receipt issued, and the order marked Partially or Fully Paid. The money stays in PartyRent\'s account.'],
      ['4', 'Seller payout', 'The shop is paid on its payout schedule, after commission ('.rtrim(rtrim(number_format((float) setting('commissionPct', 5), 2), '0'), '.').'%). See Supplier Payouts.'],
    ] as [$n, $t, $d])
      <div class="pad" style="border:1px solid var(--border);border-radius:10px"><div class="badge b-sky mb-1">Step {{ $n }}</div><div class="strong">{{ $t }}</div><div class="small muted">{{ $d }}</div></div>
    @endforeach
  </div>
</div>

<div class="stats">
  @include('partials.stat', ['label' => 'Open checkouts', 'value' => $open->count() + $openEvents->count(), 'sub' => 'waiting for the customer to pay', 'icon' => 'clock', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Verified today', 'value' => $stats['verifiedToday'], 'sub' => 'automatically, no cashier', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Collected online', 'value' => money_short($stats['collected']), 'sub' => 'held by PartyRent until payout', 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Gateway fees', 'value' => money_short($stats['fees']), 'sub' => $stats['rejected'] ? $stats['rejected'].' refused webhook(s) this week' : 'no refused webhooks this week', 'icon' => 'card', 'color' => $stats['rejected'] ? 'red' : 'sky'])
</div>

<div class="card mt-3"><div class="strong mb-1">Gateway set-up</div>
  @if(! $setup['gateway'])
    <div class="alert alert-danger">{{ icon('alert') }}<div class="small"><b>Online payment is off.</b> Customers can only pay by transfer or cash, and the cashier checks those by hand. To turn it on, set <code>PAYMONGO_SECRET_KEY</code> + <code>PAYMONGO_WEBHOOK_SECRET</code> or <code>XENDIT_SECRET_KEY</code> + <code>XENDIT_CALLBACK_TOKEN</code> in <code>.env</code>. For testing on this computer, use <code>PAYMONGO_FAKE=true</code>.</div></div>
  @else
    <div class="small">
      <div>Gateway: <b>{{ $setup['gateway'] }}</b> · @if($setup['practice'])<span class="badge b-gold">Practice mode · no real money</span>@elseif($setup['live'])<span class="badge b-green">Live</span>@else<span class="badge b-sky">Test mode</span>@endif</div>
      <div class="mt-1">Webhook: @if($setup['webhook'])<span class="badge b-green">Signature check on</span>@else<span class="badge b-red">No secret set: webhooks are refused</span>@endif @unless($setup['practice'])<span class="muted">Register <code>{{ $setup['webhookUrl'] }}</code> in the gateway dashboard.</span>@endunless</div>
      <div class="mt-1">Event company installments: {{ $setup['events'] === 'xendit' ? 'Xendit' : ($setup['events'] === 'practice' ? 'practice checkout' : 'online payment off (transfer to the business)') }}</div>
      <div class="mt-1 muted">Automatic check: every minute (<code>php artisan schedule:work</code>) and when admin or cashier pages open.
        @if($last) Last run {{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }} ({{ \App\Models\PaymentTransaction::SOURCE_LABEL[$last['source']] ?? $last['source'] }}): {{ $last['checked'] }} checked, {{ $last['paid'] }} paid.@else Not run yet.@endif</div>
    </div>
  @endif
</div>

@if($review->isNotEmpty())
  <div class="alert alert-warn mt-3">{{ icon('alert') }}<div class="small"><b>{{ $review->count() }} online payment(s) need the cashier.</b> The money arrived but did not match (for example, a different amount, or more than the balance). <a href="{{ route('cashier.verification') }}">Payments Waiting →</a></div></div>
@endif

<h2 class="mt-3">Open checkouts</h2>
<div class="card pad-0">@if($open->isEmpty() && $openEvents->isEmpty())<div class="pad">@include('partials.empty', ['emoji' => '✅', 'title' => 'No open checkouts', 'text' => 'A checkout shows here from when the customer starts paying online until the gateway confirms it. It is closed after '.\App\Domain\PaymentChecks::EXPIRE_HOURS.' hours unpaid.'])</div>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Payment</th><th>Order</th><th>Customer</th><th>Amount</th><th>Started</th><th>Last checked</th></tr></thead><tbody>
  @foreach($open as $p)
    <tr><td data-label="Payment"><b>{{ $p->code }}</b><div class="small muted">{{ $p->typeLabel() }} · {{ \App\Domain\OnlinePay::name($p->gateway) }}</div></td><td data-label="Order">{{ $p->rentalRequest->code }}</td>
      <td data-label="Customer">{{ $p->rentalRequest->customer->name }}</td><td data-label="Amount"><b>{{ money($p->amount) }}</b></td>
      <td data-label="Started">{{ fmt_datetime($p->submitted_at ?? $p->created_at) }}</td><td data-label="Last checked">{{ $p->checked_at ? $p->checked_at->diffForHumans() : 'not yet' }}</td></tr>
  @endforeach
  @foreach($openEvents as $p)
    <tr><td data-label="Payment"><b>{{ $p->code }}</b><div class="small muted">{{ $p->label }} · {{ $p->gateway === 'xendit' ? 'Xendit' : 'practice' }}</div></td><td data-label="Order">{{ $p->booking->code }}</td>
      <td data-label="Customer">{{ $p->booking->customer->name }}</td><td data-label="Amount"><b>{{ money($p->amount) }}</b></td>
      <td data-label="Started">{{ fmt_datetime($p->updated_at) }}</td><td data-label="Last checked">{{ $p->checked_at ? \Illuminate\Support\Carbon::parse($p->checked_at)->diffForHumans() : 'not yet' }}</td></tr>
  @endforeach
  </tbody></table></div>@endif</div>

<h2 class="mt-3">Gateway transactions</h2>
<div class="tabs">@foreach(\App\Http\Controllers\Admin\PaymentMonitorController::RESULTS as $k => $l)<a class="tab {{ $result === $k ? 'active' : '' }}" href="{{ route('admin.payment-monitor', $k === 'all' ? [] : ['result' => $k]) }}">{{ $l }}</a>@endforeach</div>
<div class="card pad-0">@if($log->isEmpty())<div class="pad">@include('partials.empty', ['emoji' => '🧾', 'title' => 'No gateway transactions yet', 'text' => 'Every webhook, return from the checkout page and automatic check that changes a payment is recorded here.'])</div>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>When</th><th>Payment</th><th>From</th><th>Result</th><th>Amount</th><th>Details</th></tr></thead><tbody>
  @foreach($log as $t)
    <tr><td data-label="When">{{ fmt_datetime($t->created_at) }}</td>
      <td data-label="Payment"><b>{{ $t->payment_code ?: '—' }}</b><div class="small muted">{{ $t->order_code ?: '' }} {{ $t->kind === 'event' ? '· event' : '' }}</div></td>
      <td data-label="From">{{ $t->sourceLabel() }}<div class="small muted">{{ ucfirst($t->gateway ?? '—') }} · {{ $t->verified ? 'verified' : 'not verified' }}</div></td>
      <td data-label="Result"><span class="badge {{ $t->resultBadge() }}">{{ $t->resultLabel() }}</span></td>
      <td data-label="Amount">{{ $t->amount !== null ? money($t->amount) : '—' }}@if($t->fee)<div class="small muted">fee {{ money($t->fee) }}</div>@endif</td>
      <td data-label="Details"><span class="small">{{ $t->channel ? $t->channel.' · ' : '' }}{{ $t->message }}</span>@if($t->gateway_payment_id)<div class="small muted">{{ $t->gateway_payment_id }}</div>@endif</td></tr>
  @endforeach
  </tbody></table></div><div style="padding:12px 16px">{{ $log->links('pagination::simple-default') }}</div>@endif</div>
@endsection

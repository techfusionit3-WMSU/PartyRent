@extends('layouts.dash', ['title' => 'Payment Verification', 'nav' => 'verification'])

@section('content')
<div class="page-head"><div><h1>Payment verification</h1><p>Check each submission against the bank statement or cash count. A payment only reduces the balance once you verify it.</p></div></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '✅', 'title' => 'All caught up', 'text' => 'No payments are waiting for verification.'])</div>
@else
  <div class="stack">
    @foreach($list as $p)
      @php($r = $p->rentalRequest)
      @php($f = $r->fin())
      @php($over = in_array($p->type, \App\Domain\Finance::RENTAL_TYPES, true) && $p->amount > $f['balance'] + 0.001)
      @php($dups = $p->duplicates())
      @php($dupVerified = $dups->firstWhere('status', 'verified'))
      <div class="card" id="pay-{{ $p->id }}">
        <div class="row wrap between top">
          <div><div class="row gap-sm"><b>{{ $p->code }}</b>@include('partials.badges', ['ver' => $p->status])<span class="badge b-gray plain">by {{ $p->recorded_by }}</span></div>
            <div class="small muted mt-1">{{ $r->code }} · {{ $r->event_name }} · {{ $r->customer->name }}</div></div>
          <div class="right"><div class="price-lg">{{ money($p->amount) }}</div><div class="small muted">{{ $p->typeLabel() }} · {{ $p->methodLabel() }}</div></div>
        </div>
        <dl class="kv mt-2">@if($p->payAccount)<dt>Paid to</dt><dd><b>{{ $p->payAccount->display() }}</b>@if($p->payAccount->account_name) · {{ $p->payAccount->account_name }}@endif <div class="small muted">Check this account's app or statement for the transfer.</div></dd>@endif<dt>Reference no.</dt><dd>{{ $p->reference ?: '—' }}</dd><dt>Payment date</dt><dd>{{ fmt_date($p->date) }}</dd><dt>Submitted</dt><dd>{{ fmt_datetime($p->submitted_at) }}</dd>
          <dt>Proof</dt><dd>@if($p->proof_path)<a href="{{ route('payments.proof', $p) }}" target="_blank">{{ $p->proof_name ?: 'View proof' }}</a>@elseif($p->proof_name){{ $p->proof_name }} <span class="small muted">(sample record — file not stored)</span>@else<span class="muted">None</span>@endif</dd>
          @if($p->notes)<dt>Notes</dt><dd>{{ $p->notes }}</dd>@endif
          <dt>Rental balance</dt><dd>{{ money($f['balance']) }} of {{ money($f['payable']) }} · {{ $f['status'] }}</dd></dl>
        @if($p->gateway)@php($gw = \App\Domain\OnlinePay::name($p->gateway))<div class="alert alert-info mt-1">{{ icon('card') }}<div>Paid online through {{ $gw }} (payment <b>{{ $p->reference }}</b>@if($p->gateway_fee !== null), fee {{ money($p->gateway_fee) }}@endif). The money is already in PartyRent's {{ $gw }} balance. If it can't be verified, refund it from the {{ $gw }} dashboard and record the refund.</div></div>@endif
        @if($dupVerified)<div class="alert alert-danger mt-1">{{ icon('alert') }}<div><b>Reference already used.</b> {{ $p->reference }} was verified on {{ $dupVerified->code }} ({{ $dupVerified->rentalRequest->code }}). This is probably a reused receipt. Reject it unless the bank shows two separate transfers.</div></div>
        @elseif($dups->isNotEmpty())<div class="alert alert-warn mt-1">{{ icon('alert') }}<div><b>Same reference on another submission:</b> {{ $dups->map(fn ($d) => $d->code.' ('.$d->rentalRequest->code.')')->implode(', ') }}. Only one of them can be real.</div></div>@endif
        @if($over)<div class="alert alert-danger mt-1">{{ icon('alert') }}<div>This payment exceeds the remaining balance of {{ money($f['balance']) }}. Reject it or ask the customer to correct the amount.</div></div>@endif
        <div class="row wrap mt-2" style="justify-content:flex-end;gap:8px">
          <form method="POST" action="{{ route('cashier.reject', $p) }}" class="row" style="gap:6px">@csrf<input class="input input-sm" name="reason" required placeholder="Reason for rejection" style="width:260px" maxlength="500"><button class="btn btn-danger btn-sm">{{ icon('x') }}Reject</button></form>
          <form method="POST" action="{{ route('cashier.verify', $p) }}" data-confirm="Verify {{ $p->code }} for {{ money($p->amount) }}? A receipt will be issued and the balance updated." data-confirm-title="Verify payment?" data-confirm-ok="Verify & issue receipt">@csrf<button class="btn btn-success btn-sm" @disabled($over || $dupVerified)>{{ icon('check') }}Verify</button></form>
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection

@extends('layouts.dash', ['title' => 'Payment Monitoring', 'nav' => 'payments'])

{{-- Reference SP.payments + Shared.reviewPayment --}}
@section('content')
<div class="page-head"><div><h1>Payment Monitoring</h1><p>Online payments are confirmed automatically, and PartyRent checks bank and debit transfers (the money goes to PartyRent's account). Your shop only records and confirms <b>cash</b> you receive{!! $cashiers->isNotEmpty() ? ' (Cashier: '.e($cashiers->pluck('name')->implode(', ')).')' : '' !!}. Only confirmed payments count toward balances. Your money comes in your <a href="{{ route('supplier.payouts') }}">payouts</a>.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Collected (verified)', 'value' => money_short($collected), 'sub' => 'rental payments', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Waiting for confirmation', 'value' => money_short($pend->sum('amount')), 'sub' => $pend->count().' submissions', 'icon' => 'clock', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding), 'icon' => 'cash', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Overdue', 'value' => money_short($overdue->sum(fn ($x) => $x['f']['overdueAmount'])), 'sub' => $overdue->count().' rentals', 'icon' => 'alert', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Deposits held', 'value' => money_short($depositsHeld), 'sub' => 'refundable — not income', 'icon' => 'shield', 'color' => 'purple'])
</div>
<div class="tabs">@foreach([['verify', 'Waiting for confirmation', $pend->count()], ['balances', 'Balances by order', null], ['overdue', 'Overdue', $overdue->count()], ['history', 'Payment records', $pays->count()]] as [$k, $l, $c])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('supplier.payments', ['tab' => $k]) }}">{{ $l }}@if($c !== null)<span class="count">{{ $c }}</span>@endif</a>@endforeach</div>
<div class="card pad-0">
  @if(in_array($tab, ['verify', 'history'], true))
    @php($rows = $tab === 'verify' ? $pend : $pays)
    @if($rows->isEmpty())
      <p class="muted" style="padding:20px">{{ $tab === 'verify' ? 'No payments waiting for verification 🎉' : 'No payments yet.' }}</p>
    @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Transaction</th><th>Request</th><th>Customer</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($rows as $p)
        <tr>
          <td data-label="Transaction"><div><b>{{ $p->code }}</b><div class="small muted">{{ fmt_date($p->date) }}</div></div></td>
          <td data-label="Request"><a href="{{ route('supplier.requests.show', $p->rentalRequest) }}">{{ $p->rentalRequest->code }}</a></td>
          <td data-label="Customer">{{ $p->rentalRequest->customer->name }}</td>
          <td data-label="Type">{{ $p->typeLabel() }}</td>
          <td data-label="Method"><div>{{ $p->methodLabel() }}<div class="small muted">{{ $p->reference }}</div></div></td>
          <td data-label="Amount" class="num"><b>{{ money($p->amount) }}</b></td>
          @php($cv = \App\Domain\Rentals::canVerify($p, auth()->user()))
          <td data-label="Status">@include('partials.badges', ['ver' => $p->status])@if($p->status === 'pending' && ! $cv)<div class="small muted">PartyRent is checking</div>@endif</td>
          <td>@if($p->status === 'pending')<button class="btn btn-xs {{ $cv ? 'btn-primary' : '' }}" data-modal="pay{{ $p->id }}">{{ $cv ? 'Review' : 'View' }}</button>@elseif($p->receipt_no)<a class="btn btn-xs" href="{{ route('payments.receipt', $p) }}" target="_blank">{{ $p->receipt_no }}</a>@endif</td>
        </tr>
      @endforeach
    </tbody></table></div>
    @endif
  @else
    @php($list = ($tab === 'overdue' ? $overdue : $fins)->sortByDesc(fn ($x) => $x['f']['balance']))
    @if($list->isEmpty())
      <p class="muted" style="padding:20px">{{ $tab === 'overdue' ? 'Nothing overdue.' : 'No orders yet.' }}</p>
    @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th>Payment plan</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Next deadline</th><th>Deposit</th><th>Status</th></tr></thead><tbody>
      @foreach($list as ['r' => $r, 'f' => $f])
        <tr>
          <td data-label="Request"><div><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->event_name }}</div></div></td>
          <td data-label="Customer">{{ $r->customer->name }}</td>
          <td data-label="Payment plan"><span class="small">{{ \App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '—' }}</span></td>
          <td data-label="Total" class="num">{{ money($f['payable']) }}</td>
          <td data-label="Paid" class="num">{{ money($f['paidRental']) }}</td>
          <td data-label="Outstanding" class="num"><b>{{ money($f['balance']) }}</b></td>
          <td data-label="Next deadline">@if($f['next'])<span class="small">{{ $f['next']['label'] }}<br>{{ fmt_date($f['next']['due']) }}</span>@else — @endif</td>
          <td data-label="Deposit"><span class="small">{{ $f['depositStatus'] }}</span></td>
          <td data-label="Status">@include('partials.badges', ['pay' => $f['status']])</td>
        </tr>
      @endforeach
    </tbody></table></div>
    @endif
  @endif
</div>

@foreach($pend as $p)
  @php($r = $p->rentalRequest)
  @php($f = $r->fin())
  @php($rental = in_array($p->type, \App\Domain\Finance::RENTAL_TYPES, true))
  @php($dupRef = $p->reference && \App\Models\Payment::where('id', '!=', $p->id)->whereRaw('LOWER(reference) = ?', [mb_strtolower($p->reference)])->where('status', '!=', 'rejected')->exists())
  <div class="modal-backdrop hidden" id="pay{{ $p->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>{{ $p->code }} · {{ $p->typeLabel() }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <div class="modal-body">
      <dl class="kv"><dt>Request</dt><dd><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a> · {{ $r->event_name }}</dd><dt>Customer</dt><dd>{{ $r->customer->name }}</dd><dt>Amount</dt><dd><b>{{ money($p->amount) }}</b></dd><dt>Method</dt><dd>{{ $p->methodLabel() }}</dd><dt>Reference</dt><dd>{{ $p->reference ?: '—' }}</dd><dt>Date</dt><dd>{{ fmt_date($p->date) }}</dd>@if($p->notes)<dt>Notes</dt><dd>{{ $p->notes }}</dd>@endif</dl>
      @if($p->proof_path)<a class="btn btn-sm mt-1" href="{{ route('payments.proof', $p) }}" target="_blank">{{ icon('image') }}View payment proof</a>@endif
      <div class="card card-flat soft mt-2" style="padding:14px"><b>Balance check</b>
        <div class="sum-line"><span class="muted">Total rental amount</span><span>{{ money($f['payable']) }}</span></div>
        <div class="sum-line"><span class="muted">Verified so far</span><span>{{ money($f['paidRental']) }}</span></div>
        <div class="sum-line strong"><span>Outstanding before this payment</span><span>{{ money($f['balance']) }}</span></div>
        @if($rental)<div class="sum-line"><span class="muted">Outstanding after verification</span><span>{{ money(max(0, $f['balance'] - $p->amount)) }}</span></div>@else<div class="small muted">{{ $p->typeLabel() }} is tracked separately from rental charges.</div>@endif
      </div>
      @if($dupRef)<div class="alert alert-warn mt-1">{{ icon('alert') }}<div>This reference number was already used on another payment. Check for duplicates.</div></div>@endif
      @if($rental && $p->amount > $f['balance'] + 0.001)<div class="alert alert-danger mt-1">{{ icon('alert') }}<div>Amount exceeds the outstanding balance. Reject it or ask the customer to correct it.</div></div>@endif
      @php($cv = \App\Domain\Rentals::canVerify($p, auth()->user()))
      @unless($cv)<div class="alert alert-info mt-2">{{ icon('clock') }}<div>This {{ strtolower($p->methodLabel()) }} went to PartyRent's account, so PartyRent checks it. You are notified as soon as it is confirmed.</div></div>@endunless
      @if($cv)
        <form method="POST" action="{{ route('supplier.payments.reject', $p) }}" class="mt-2" id="rej{{ $p->id }}" hidden>@csrf<div class="field"><label>Reason (sent to the customer)</label><textarea class="input" name="reason" required placeholder="e.g. Reference number not found / proof unreadable"></textarea></div><button class="btn btn-danger">Reject payment</button></form>
      @endif
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Close</button>
      @if($cv)
        <button type="button" class="btn btn-danger" onclick="var f=document.getElementById('rej{{ $p->id }}');f.hidden=false;f.querySelector('textarea').focus()">{{ icon('x') }}Reject</button>
        <form method="POST" action="{{ route('supplier.payments.verify', $p) }}" data-confirm-title="Verify payment?" data-confirm="Confirm that {{ money($p->amount) }} (ref {{ $p->reference ?: '—' }}) was received. A receipt will be issued and the balance updated for the customer." data-confirm-ok="Verify">@csrf<button class="btn btn-success">{{ icon('check') }}Verify &amp; issue receipt</button></form>
      @endif
    </div>
  </div></div>
@endforeach
@endsection

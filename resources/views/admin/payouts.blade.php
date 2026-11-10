@extends('layouts.dash', ['title' => 'Supplier Payouts', 'nav' => 'payouts'])

@section('content')
<div class="page-head"><div><h1>Supplier payouts</h1><p>Customers pay PartyRent. The money is held until the rental is completed, then released to the supplier minus the {{ rtrim(rtrim(number_format($pct, 2), '0'), '.') }}% platform commission. Damage compensation is passed through without a fee; security deposits are never paid out.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Held for active rentals', 'value' => money_short($totals['escrow']), 'sub' => 'in escrow', 'icon' => 'shield', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'To send', 'value' => money_short($totals['pending']), 'sub' => $pending->count().' released payout'.($pending->count() === 1 ? '' : 's'), 'icon' => 'cash', 'color' => 'gold', 'href' => route('admin.payouts', ['tab' => 'pending'])])
  @include('partials.stat', ['label' => 'Awaiting release', 'value' => money_short($totals['awaiting']), 'sub' => $awaiting->count().' closed request'.($awaiting->count() === 1 ? '' : 's'), 'icon' => 'clock', 'color' => 'pink', 'href' => route('admin.payouts', ['tab' => 'release'])])
  @include('partials.stat', ['label' => 'Commission earned', 'value' => money_short($totals['commission']), 'sub' => 'from released payouts', 'icon' => 'chart', 'color' => 'purple'])
</div>
<div class="tabs">@foreach(['pending' => ['To send', $pending->count()], 'release' => ['Awaiting release', $awaiting->count()], 'paid' => ['Paid', null], 'suppliers' => ['By supplier', null]] as $k => [$l, $n])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.payouts', ['tab' => $k]) }}">{{ $l }}@if($n !== null)<span class="count">{{ $n }}</span>@endif</a>@endforeach</div>
<div class="card pad-0">
  @if($tab === 'pending')
    @if($pending->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing to send', 'text' => 'Released payouts show here until you record the transfer.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Payout</th><th>Supplier · account</th><th class="num">Income</th><th class="num">Commission</th><th class="num">Compensation</th><th class="num">Send</th><th>Record transfer</th></tr></thead><tbody>
      @foreach($pending as $p)<tr>
        <td data-label="Payout"><b>{{ $p->code }}</b><div class="small muted">{{ $p->rentalRequest->code }} · released {{ fmt_date($p->created_at->toDateString()) }}</div></td>
        <td data-label="Supplier"><b>{{ $p->supplier->name }}</b><div class="small {{ $p->supplier->hasPayoutAccount() ? 'muted' : '' }}" @unless($p->supplier->hasPayoutAccount()) style="color:var(--red)" @endunless>{{ $p->supplier->hasPayoutAccount() ? $p->supplier->payoutAccountLabel() : 'No payout account yet' }}</div></td>
        <td data-label="Income" class="num">{{ money($p->income) }}</td>
        <td data-label="Commission" class="num">− {{ money($p->commission) }}<div class="small muted">{{ rtrim(rtrim(number_format($p->commission_pct, 2), '0'), '.') }}%</div></td>
        <td data-label="Compensation" class="num">{{ $p->compensation > 0 ? '+ '.money($p->compensation) : '—' }}</td>
        <td data-label="Send" class="num"><b>{{ money($p->net) }}</b></td>
        <td data-label="Transfer"><form method="POST" action="{{ route('admin.payouts.pay', $p) }}" class="row" style="gap:6px;flex-wrap:wrap">@csrf
          <select class="input input-sm" name="method" style="width:auto" aria-label="Method">@foreach(\App\Models\Payout::METHOD_LABEL as $k => $l)<option value="{{ $k }}" @selected($p->supplier->payout_method === $k)>{{ $l }}</option>@endforeach</select>
          <input class="input input-sm" name="reference" placeholder="Reference no." required maxlength="80" style="width:140px" aria-label="Reference number">
          <button class="btn btn-sm btn-primary">Mark paid</button></form></td>
      </tr>@endforeach
    </tbody></table></div>@endif
  @elseif($tab === 'release')
    @if($awaiting->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing waiting', 'text' => 'Completed rentals are released automatically. Cancelled requests where money was kept, or completed ones that still had a pending payment, show here.'])@else
    <div class="alert alert-info" style="margin:12px 16px">{{ icon('info') }}<div class="small">Release once refunds are settled: check that the cashier has recorded any refund the customer is owed.</div></div>
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Supplier</th><th>Status</th><th class="num">Held</th><th class="num">Payout</th><th></th></tr></thead><tbody>
      @foreach($awaiting as $r)@php($b = \App\Domain\Payouts::breakdown($r))<tr>
        <td data-label="Request"><b>{{ $r->code }}</b><div class="small muted">{{ $r->event_name }} · {{ $r->customer->name }}</div></td>
        <td data-label="Supplier">{{ $r->supplier->name }}</td>
        <td data-label="Status">@include('partials.badges', ['status' => $r->status])@if($r->payments->where('status', 'pending')->isNotEmpty())<div class="small" style="color:var(--red)">payment pending verification</div>@endif</td>
        <td data-label="Held" class="num">{{ money($b['income'] + $b['compensation']) }}</td>
        <td data-label="Payout" class="num"><b>{{ money($b['net']) }}</b><div class="small muted">after {{ money($b['commission']) }} commission</div></td>
        <td><form method="POST" action="{{ route('admin.payouts.release', $r) }}">@csrf<button class="btn btn-sm">Release</button></form></td>
      </tr>@endforeach
    </tbody></table></div>@endif
  @elseif($tab === 'paid')
    @if($paid->isEmpty())@include('partials.empty', ['text' => 'No payouts sent yet.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Paid</th><th>Payout</th><th>Supplier</th><th>Method · reference</th><th class="num">Commission</th><th class="num">Net sent</th><th>By</th></tr></thead><tbody>
      @foreach($paid as $p)<tr><td data-label="Paid">{{ fmt_date($p->paid_at->toDateString()) }}</td><td data-label="Payout"><b>{{ $p->code }}</b><div class="small muted">{{ $p->rentalRequest->code }}</div></td><td data-label="Supplier">{{ $p->supplier->name }}</td><td data-label="Reference">{{ $p->methodLabel() }} · {{ $p->reference }}</td><td data-label="Commission" class="num">{{ money($p->commission) }}</td><td data-label="Net" class="num"><b>{{ money($p->net) }}</b></td><td data-label="By">{{ $p->payer->name ?? '—' }}</td></tr>@endforeach
    </tbody></table></div>@endif
  @else
    @if($suppliers->isEmpty())@include('partials.empty', ['text' => 'No supplier money on the platform yet.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Supplier</th><th>Payout account</th><th class="num">In escrow</th><th class="num">Awaiting release</th><th class="num">To send</th><th class="num">Paid out</th><th class="num">Commission</th></tr></thead><tbody>
      @foreach($suppliers as $s)@php($x = $sum[$s->id])<tr><td data-label="Supplier"><b>{{ $s->name }}</b></td><td data-label="Account" class="small">{{ $s->payoutAccountLabel() }}</td><td data-label="In escrow" class="num">{{ money($x['escrow']) }}</td><td data-label="Awaiting" class="num">{{ money($x['awaiting']) }}</td><td data-label="To send" class="num">{{ money($x['pending']) }}</td><td data-label="Paid out" class="num">{{ money($x['paid']) }}</td><td data-label="Commission" class="num">{{ money($x['commission']) }}</td></tr>@endforeach
    </tbody></table></div>@endif
  @endif
</div>
@endsection

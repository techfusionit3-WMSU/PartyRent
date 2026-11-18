@extends('layouts.dash', ['title' => 'Supplier Payouts', 'nav' => 'payouts'])

@section('content')
@php($pctTxt = rtrim(rtrim(number_format($pct, 2), '0'), '.'))
<div class="page-head"><div><h1>Supplier payouts</h1><p>Customers pay PartyRent. When a rental is completed, the shop's earnings (items minus {{ $pctTxt }}% commission, plus delivery, vouchers and damage charges) wait for the shop's next payout. Each shop is paid every 15 days or once a month, as it chooses. Cash the shop collected itself is taken off its payout.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'To send now', 'value' => money_short($pending->sum('net')), 'sub' => $pending->count().' payout'.($pending->count() === 1 ? '' : 's'), 'icon' => 'cash', 'color' => 'gold', 'href' => route('admin.payouts', ['tab' => 'pending'])])
  @include('partials.stat', ['label' => 'For upcoming payouts', 'value' => money_short($totals['next']), 'sub' => 'completed rentals, not due yet', 'icon' => 'calendar', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Held for active rentals', 'value' => money_short($totals['escrow']), 'sub' => 'in escrow', 'icon' => 'shield', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Shops owe PartyRent', 'value' => money_short($totals['owed']), 'sub' => $owed->count().' shop balance'.($owed->count() === 1 ? '' : 's'), 'icon' => 'alert', 'color' => 'red', 'href' => route('admin.payouts', ['tab' => 'owed'])])
  @include('partials.stat', ['label' => 'Commission earned', 'value' => money_short($totals['commission']), 'sub' => 'from completed rentals', 'icon' => 'chart', 'color' => 'purple'])
</div>
<div class="tabs">@foreach(['pending' => ['To send', $pending->count()], 'owed' => ['Shops that owe', $owed->count()], 'release' => ['Awaiting release', $awaiting->count()], 'paid' => ['Sent', null], 'suppliers' => ['By shop', null]] as $k => [$l, $n])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.payouts', ['tab' => $k]) }}">{{ $l }}@if($n !== null)<span class="count">{{ $n }}</span>@endif</a>@endforeach</div>
<div class="card pad-0">
  @if($tab === 'pending')
    @if($pending->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing to send', 'text' => 'Payouts appear here the day after a shop\'s payout period ends.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Payout</th><th>Shop · account</th><th class="num">Earned</th><th class="num">Cash kept · balance</th><th class="num">Send</th><th>Transfer</th></tr></thead><tbody>
      @foreach($pending as $p)<tr>
        <td data-label="Payout"><b>{{ $p->code }}</b><div class="small muted">{{ $p->periodLabel() }} · {{ $p->lines->count() }} rental{{ $p->lines->count() === 1 ? '' : 's' }}</div><details class="small"><summary class="muted">rentals</summary>@foreach($p->lines as $l)<div>{{ $l->rentalRequest->code }} · {{ money($l->earned) }}{{ $l->cash_kept != 0 ? ' (cash '.money($l->cash_kept).')' : '' }}</div>@endforeach</details></td>
        <td data-label="Shop"><b>{{ $p->supplier->name }}</b><div class="small {{ $p->supplier->hasPayoutAccount() ? 'muted' : '' }}" @unless($p->supplier->hasPayoutAccount()) style="color:var(--red)" @endunless>{{ $p->supplier->hasPayoutAccount() ? $p->supplier->payoutAccountLabel() : 'No payout account yet' }}</div></td>
        <td data-label="Earned" class="num">{{ money($p->earned) }}<div class="small muted">after {{ money($p->commission) }} commission</div></td>
        <td data-label="Cash kept · balance" class="num">{{ $p->cash_kept != 0 ? '− '.money($p->cash_kept) : '—' }}@if($p->carried_in != 0)<div class="small muted">{{ $p->carried_in < 0 ? '− '.money(-$p->carried_in) : '+ '.money($p->carried_in) }} from before</div>@endif</td>
        <td data-label="Send" class="num"><b>{{ money($p->net) }}</b></td>
        <td data-label="Transfer">@if($p->isSending())
          <span class="badge b-gold">Sending via Xendit</span><div class="small muted mt-1">{{ $p->gateway_ref }}</div>
          <form method="POST" action="{{ route('admin.payouts.check', $p) }}" class="mt-1">@csrf<button class="btn btn-xs">{{ icon('refresh') }}Check status</button></form>
        @elseif(! $p->supplier->hasPayoutAccount())<span class="small muted">Waiting for the shop's payout account</span>@else
          @if($p->failure)<div class="small mb-1" style="color:var(--red)">Last try failed: {{ $p->failure }}</div>@endif
          @if($xendit)@php($ch = \App\Domain\XenditPayouts::channel($p->supplier))
            @if($ch)<form method="POST" action="{{ route('admin.payouts.send', $p) }}">@csrf<button class="btn btn-sm btn-primary">{{ icon('send') }}Send via Xendit</button></form>
            @else<div class="small muted">Xendit can't send to "{{ $p->supplier->payout_bank }}". Send it by hand.</div>@endif
          @endif
          <details class="mt-1" @unless($xendit) open @endunless><summary class="small muted">{{ $xendit ? 'Sent it by hand instead?' : 'Record the transfer' }}</summary>
          <form method="POST" action="{{ route('admin.payouts.pay', $p) }}" class="row mt-1" style="gap:6px;flex-wrap:wrap">@csrf
          <select class="input input-sm" name="method" style="width:auto" aria-label="Method">@foreach(\App\Models\Payout::METHOD_LABEL as $k => $l)<option value="{{ $k }}" @selected($p->supplier->payout_method === $k)>{{ $l }}</option>@endforeach</select>
          <input class="input input-sm" name="reference" placeholder="Reference no." required maxlength="80" style="width:140px" aria-label="Reference number">
          <button class="btn btn-sm">Mark paid</button></form></details>@endif</td>
      </tr>@endforeach
    </tbody></table></div>@endif
  @elseif($tab === 'owed')
    @if($owed->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'No shop owes PartyRent', 'text' => 'When a shop collects more cash than it earned in a period, the difference shows here and is taken from its next payout.'])@else
    <div class="alert alert-info" style="margin:12px 16px">{{ icon('info') }}<div class="small">These balances are taken from each shop's next payout automatically. After {{ \App\Domain\Payouts::OWE_DAYS }} days, cash payments are paused for that shop. If the shop pays you directly, record it here.</div></div>
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Shop</th><th>From payout</th><th class="num">Owes</th><th>Since</th><th>Shop paid directly</th></tr></thead><tbody>
      @foreach($owed as $o)@php($late = $o->period_end->toDateString() < add_days(today_iso(), -\App\Domain\Payouts::OWE_DAYS))<tr>
        <td data-label="Shop"><b>{{ $o->supplier->name }}</b>@if($late)<div class="small" style="color:var(--red)">cash paused</div>@endif</td>
        <td data-label="From payout">{{ $o->code }}<div class="small muted">{{ $o->periodLabel() }}</div></td>
        <td data-label="Owes" class="num"><b>{{ money(-$o->net) }}</b></td>
        <td data-label="Since">{{ fmt_date($o->period_end->toDateString()) }}</td>
        <td data-label="Settle"><form method="POST" action="{{ route('admin.payouts.settle', $o) }}" class="row" style="gap:6px;flex-wrap:wrap" data-confirm-title="Settle {{ money(-$o->net) }}?" data-confirm="Only if {{ $o->supplier->name }} already paid PartyRent this amount." data-confirm-ok="Settle">@csrf<input class="input input-sm" name="reference" placeholder="Their payment ref." required maxlength="80" style="width:150px" aria-label="Reference"><button class="btn btn-sm">Mark settled</button></form></td>
      </tr>@endforeach
    </tbody></table></div>@endif
  @elseif($tab === 'release')
    @if($awaiting->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing waiting', 'text' => 'Completed rentals are added to payouts automatically. Cancelled requests where money was kept, or completed ones that still had a payment to confirm, show here.'])@else
    <div class="alert alert-info" style="margin:12px 16px">{{ icon('info') }}<div class="small">Release once refunds are settled. The amount is added to the shop's next payout.</div></div>
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Shop</th><th>Status</th><th class="num">Adds to payout</th><th></th></tr></thead><tbody>
      @foreach($awaiting as $r)@php($b = \App\Domain\Payouts::breakdown($r))<tr>
        <td data-label="Request"><b>{{ $r->code }}</b><div class="small muted">{{ $r->event_name }} · {{ $r->customer->name }}</div></td>
        <td data-label="Shop">{{ $r->supplier->name }}</td>
        <td data-label="Status">@include('partials.badges', ['status' => $r->status])@if($r->payments->where('status', 'pending')->isNotEmpty())<div class="small" style="color:var(--red)">payment waiting for confirmation</div>@endif</td>
        <td data-label="Adds to payout" class="num"><b>{{ $b['net'] < 0 ? '− '.money(-$b['net']) : money($b['net']) }}</b><details class="small"><summary class="muted">details</summary>@include('partials.payout-breakdown', ['b' => $b, 'netLabel' => 'Adds to payout'])</details></td>
        <td><form method="POST" action="{{ route('admin.payouts.release', $r) }}">@csrf<button class="btn btn-sm">Release</button></form></td>
      </tr>@endforeach
    </tbody></table></div>@endif
  @elseif($tab === 'paid')
    @if($paid->isEmpty())@include('partials.empty', ['text' => 'No payouts sent yet.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Payout</th><th>Shop</th><th>Method · reference</th><th class="num">Commission</th><th class="num">Amount</th><th>By</th></tr></thead><tbody>
      @foreach($paid as $p)<tr><td data-label="Date">{{ fmt_date($p->paid_at->toDateString()) }}</td><td data-label="Payout"><b>{{ $p->code }}</b><div class="small muted">{{ $p->periodLabel() }}{{ $p->status === 'settled' ? ' · shop paid its balance' : '' }}</div></td><td data-label="Shop">{{ $p->supplier->name }}</td><td data-label="Reference">{{ $p->status === 'settled' ? 'From the shop' : $p->methodLabel().($p->gateway === 'xendit' ? ' via Xendit' : '') }} · {{ $p->reference }}</td><td data-label="Commission" class="num">{{ money($p->commission) }}</td><td data-label="Amount" class="num"><b>{{ $p->net < 0 ? money(-$p->net).' received' : money($p->net) }}</b></td><td data-label="By">{{ $p->payer->name ?? ($p->gateway === 'xendit' ? 'Automatic' : '—') }}</td></tr>@endforeach
    </tbody></table></div>@endif
  @else
    @if($suppliers->isEmpty())@include('partials.empty', ['text' => 'No shop money on the platform yet.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Shop</th><th>Schedule · next payout</th><th>Payout account</th><th class="num">In escrow</th><th class="num">Next payout</th><th class="num">To send</th><th class="num">Paid out</th><th class="num">Owes</th></tr></thead><tbody>
      @foreach($suppliers as $s)@php($x = $sum[$s->id])<tr><td data-label="Shop"><b>{{ $s->name }}</b></td><td data-label="Schedule" class="small">{{ \App\Domain\Payouts::SCHEDULES[\App\Domain\Payouts::scheduleOf($s)] }}<br>{{ fmt_date(\App\Domain\Payouts::nextPayoutDate($s)) }}</td><td data-label="Account" class="small">{{ $s->payoutAccountLabel() }}</td><td data-label="In escrow" class="num">{{ money($x['escrow']) }}</td><td data-label="Next payout" class="num">{{ money($x['next']) }}</td><td data-label="To send" class="num">{{ money($x['pending']) }}</td><td data-label="Paid out" class="num">{{ money($x['paid']) }}</td><td data-label="Owes" class="num">{{ $x['owed'] > 0 ? money($x['owed']) : '—' }}</td></tr>@endforeach
    </tbody></table></div>@endif
  @endif
</div>
@endsection

@extends('layouts.dash', ['title' => 'Cashier Dashboard', 'nav' => 'dashboard'])

@section('content')
<div class="page-head"><div><h1>Cashier dashboard</h1><p>{{ fmt_date(today_iso()) }} · only verified payments count toward customer balances.</p></div><div class="row wrap"><a class="btn" href="{{ route('cashier.record') }}">{{ icon('cash') }}Record payment</a><a class="btn btn-primary" href="{{ route('cashier.verification') }}">{{ icon('check') }}Verify payments{{ $pending->count() ? ' ('.$pending->count().')' : '' }}</a></div></div>
<div class="stats">
  @include('partials.stat', ['label' => 'Awaiting verification', 'value' => $pending->count(), 'sub' => money_short($pending->sum('amount')), 'icon' => 'clock', 'color' => 'gold', 'href' => route('cashier.verification')])
  @include('partials.stat', ['label' => 'Collected today', 'value' => money_short($todayIn), 'sub' => $todayCount.' verified transactions', 'icon' => 'check', 'color' => 'teal', 'href' => route('cashier.history')])
  @include('partials.stat', ['label' => 'Refunded today', 'value' => money_short($todayOut), 'sub' => 'rental & deposit refunds', 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Overdue accounts', 'value' => $overdue->count(), 'sub' => money_short($overdue->sum(fn ($r) => $r->fin()['overdueAmount'])).' overdue', 'icon' => 'alert', 'color' => 'red', 'href' => route('cashier.balances')])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding), 'sub' => 'active rentals', 'icon' => 'clock', 'color' => 'pink', 'href' => route('cashier.balances')])
  @include('partials.stat', ['label' => 'Deposits held', 'value' => money_short($depositsHeld), 'sub' => 'refundable, not income', 'icon' => 'shield', 'color' => 'sky'])
</div>
<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Oldest pending submissions</h3><a class="small strong" href="{{ route('cashier.verification') }}">Open queue</a></div>
    @forelse($pending->take(6) as $p)
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>{{ $p->code }}</b> · {{ $p->rentalRequest->customer->name }}<div class="small muted">{{ $p->rentalRequest->code }} · {{ $p->typeLabel() }} · {{ $p->methodLabel() }} · {{ time_ago($p->submitted_at) }}</div></div><b>{{ money($p->amount) }}</b></div>
    @empty <p class="muted">The queue is empty. 🎉</p> @endforelse
  </div>
  <div class="card"><div class="card-head"><h3>Recently processed</h3><a class="small strong" href="{{ route('cashier.history') }}">History</a></div>
    @forelse($recent as $p)
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>{{ $p->code }}</b> @include('partials.badges', ['ver' => $p->status])<div class="small muted">{{ $p->rentalRequest->code }} · {{ $p->verifier->name ?? '—' }} · {{ fmt_datetime($p->verified_at) }}</div></div><b>{{ $p->isOutflow() ? '− ' : '' }}{{ money($p->amount) }}</b></div>
    @empty <p class="muted">Nothing processed yet.</p> @endforelse
  </div>
</div>
@endsection

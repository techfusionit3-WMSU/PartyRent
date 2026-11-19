@extends('layouts.dash', ['title' => 'Cashier Dashboard', 'nav' => 'dashboard'])

{{-- Reference CS.dashboard --}}
@section('content')
<div class="page-head"><div><h1>Payment overview</h1><p>{{ fmt_date(today_iso()) }} · Cashier {{ auth()->user()->name }}@if($shop) · <b>{{ $shop->name }}</b>@endif</p></div><div class="row wrap"><a class="btn" href="{{ route('cashier.record') }}">{{ icon('cash') }}Record payment</a><a class="btn btn-primary" href="{{ route('cashier.verification') }}">{{ icon('check') }}Verify payments ({{ $pending->count() }})</a></div></div>
@if($shop)
@include('partials.guides', ['welcome' => \App\Domain\Guides::welcome(auth()->user(), 'cashier')])
@include('partials.needs-you', ['tasks' => \App\Domain\Tasks::forModule(auth()->user(), 'cashier')])
@endif
<div class="stats mb-3">
  @include('partials.stat', ['label' => "Today's verified payments", 'value' => money_short($today->sum('amount')), 'sub' => $today->count().' transactions', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Pending verification', 'value' => $pending->count(), 'sub' => money_short($pending->sum('amount')), 'icon' => 'clock', 'color' => 'gold', 'href' => route('cashier.verification')])
  @include('partials.stat', ['label' => 'Total verified', 'value' => money_short($totalVerified), 'sub' => 'all rental payments', 'icon' => 'card', 'color' => 'sky', 'href' => route('cashier.history')])
  @include('partials.stat', ['label' => 'Partial payments', 'value' => $partial->count(), 'sub' => money_short($partialLeft).' remaining', 'icon' => 'cash', 'color' => 'purple', 'href' => route('cashier.balances')])
  @include('partials.stat', ['label' => 'Overdue balances', 'value' => $overdue->count(), 'sub' => money_short($overdue->sum(fn ($r) => $r->fin()['overdueAmount'])).' overdue', 'icon' => 'alert', 'color' => 'red', 'href' => route('cashier.balances')])
  @include('partials.stat', ['label' => 'Down payments (verified)', 'value' => money_short($downPayments), 'icon' => 'tag', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Deposits held', 'value' => money_short($depositsHeld), 'sub' => 'refundable', 'icon' => 'shield', 'color' => 'sky'])
</div>
<div class="grid grid-2">
  <div class="card"><div class="card-head"><h3>Pending verifications</h3><a class="small strong" href="{{ route('cashier.verification') }}">Open queue</a></div>
    @forelse($pending as $p)
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>{{ money($p->amount) }}</b> · {{ $p->typeLabel() }}<div class="small muted">{{ $p->rentalRequest->customer->name }} · {{ $p->rentalRequest->code }} · {{ $p->rentalRequest->supplier->name }}</div></div><a class="btn btn-sm btn-primary" href="{{ route('cashier.verification') }}#pay-{{ $p->id }}">Review</a></div>
    @empty <p class="muted">Queue is clear 🎉</p> @endforelse
  </div>
  <div class="card"><div class="card-head"><h3>Overdue accounts</h3><a class="small strong" href="{{ route('cashier.balances') }}">View all</a></div>
    @forelse($overdue as $r)
      @php($f = $r->fin())
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>{{ $r->code }}</b> · {{ $r->customer->name }}<div class="small muted">{{ $r->supplier->name }} · {{ collect($f['schedule'])->firstWhere('state', 'overdue')['label'] ?? '' }}</div></div><b style="color:var(--red)">{{ money($f['overdueAmount']) }}</b></div>
    @empty <p class="muted">No overdue accounts.</p> @endforelse
  </div>
  <div class="card"><h3>Payments by method (verified)</h3>@include('partials.chart', ['kind' => 'donut', 'rows' => $byMethod, 'opts' => ['money' => true, 'center' => 'verified']])</div>
  <div class="card"><div class="card-head"><h3>Recent receipts</h3><a class="small strong" href="{{ route('cashier.history') }}">All receipts</a></div>
    @forelse($receipts as $p)
      <div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div><b>{{ $p->receipt_no }}</b> · {{ money($p->amount) }}<div class="small muted">{{ $p->rentalRequest->code }} · {{ fmt_date($p->date) }}</div></div><a class="btn btn-xs" target="_blank" href="{{ route('payments.receipt', $p) }}">View</a></div>
    @empty <p class="muted">No receipts yet.</p> @endforelse
  </div>
</div>
@endsection

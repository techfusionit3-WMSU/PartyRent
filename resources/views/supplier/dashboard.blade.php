@extends('layouts.dash', ['title' => 'Dashboard', 'nav' => 'dashboard'])

@section('content')
<div class="page-head"><div><h1>Good day, {{ auth()->user()->firstName() }}! {{ $sup->logo }}</h1><p>{{ $sup->name }} · {{ fmt_date(today_iso()) }}</p></div>
  <div class="row wrap"><a class="btn" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add item</a><a class="btn btn-primary" href="{{ route('supplier.requests') }}">{{ icon('list') }}Requests{{ $toReview ? ' ('.$toReview.')' : '' }}</a></div>
</div>

<div class="card mb-2">
  <div class="card-head"><h3>Needs your attention</h3></div>
  @forelse($todo as $t)
    <div class="todo-row">
      <div class="todo-ico t-{{ $t['tone'] }}">{{ icon($t['icon']) }}</div>
      <div class="grow"><div class="strong">{{ $t['title'] }}</div><div class="small muted">{!! $t['body'] !!}</div></div>
      <a class="btn btn-sm" href="{{ $t['href'] }}">{{ $t['cta'] }}</a>
    </div>
  @empty
    <p class="muted mb-0">{{ icon('check') }} All caught up. Nothing needs you right now.</p>
  @endforelse
  @if($waiting)<p class="small muted mt-1 mb-0">{{ $waiting }} more request{{ $waiting > 1 ? 's are' : ' is' }} waiting for the customer's down payment. You can accept once it is paid.</p>@endif
</div>

<div class="stats">
  @include('partials.stat', ['label' => 'Requests to accept', 'value' => $toReview, 'sub' => 'down payment received', 'icon' => 'clock', 'color' => 'gold', 'href' => route('supplier.requests')])
  @include('partials.stat', ['label' => 'Active rentals', 'value' => $active, 'sub' => 'booked, out, or returning', 'icon' => 'truck', 'color' => 'pink', 'href' => route('supplier.schedule')])
  @include('partials.stat', ['label' => 'Earned this month', 'value' => money_short($earned), 'sub' => 'verified payments', 'icon' => 'chart', 'color' => 'purple', 'href' => route('supplier.reports')])
  @include('partials.stat', ['label' => 'Free to rent today', 'value' => number_format($available), 'sub' => $items.' item'.($items === 1 ? '' : 's').' listed', 'icon' => 'box', 'color' => 'teal', 'href' => route('supplier.inventory')])
</div>

<div class="card mt-3">
  <div class="card-head"><h3>Coming up · next 14 days</h3><a class="small strong" href="{{ route('supplier.schedule') }}">Full schedule</a></div>
  @forelse($upcoming as $r)
    <a class="row between" href="{{ route('supplier.requests.show', $r) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><b>{{ fmt_date($r->start_date, false) }}</b> · {{ $r->event_name }}<div class="small muted">{{ $r->code }} · {{ $r->customer->name }} · {{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }}</div></div><div class="stack-sm" style="align-items:flex-end">@include('partials.badges', ['status' => $r->status])@include('partials.badges', ['pay' => $r->fin()['status']])</div></a>
  @empty <p class="muted mb-0">No rentals in the next 14 days.</p> @endforelse
  <p class="small mt-2 mb-0"><a href="{{ route('supplier.reports') }}">See charts and reports →</a></p>
</div>
@endsection

@extends('layouts.dash', ['title' => 'Inventory Calendar', 'nav' => 'calendar'])

@section('content')
@if($prods->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📅', 'title' => 'No items yet', 'text' => __('Each item gets a calendar of booked and free days once you add it.'), 'action' => auth()->user()->orgCan('products.manage') ? '<a class="btn btn-primary" href="'.e(route('supplier.products.create')).'">'.icon('plus').e(__('Add a product')).'</a>' : ''])</div>
@else
@php($q = fn (array $o) => route('supplier.calendar', array_merge(['item' => $p->id, 'view' => $view, 'd' => $cursor], $o)))
<div class="page-head"><div><h1>Inventory Calendar</h1><p>Select an item to see its rental schedule.</p></div>
  <div class="row wrap">
    <form method="GET"><input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="d" value="{{ $cursor }}"><select class="input input-sm cal-item" name="item" style="width:auto;max-width:260px" aria-label="Item" data-autosubmit>@foreach($prods as $x)<option value="{{ $x->id }}" @selected($x->id === $p->id)>{{ $x->name }}</option>@endforeach</select></form>
    <div class="seg">@foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $k => $l)<a class="{{ $view === $k ? 'active' : '' }}" href="{{ $q(['view' => $k, 'd' => $k === 'day' ? $sel : $cursor]) }}">{{ $l }}</a>@endforeach</div>
  </div>
</div>
<div class="cal-layout" style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start">
  <div class="card">
    <div class="row between mb-2"><a class="btn btn-sm btn-icon" href="{{ $q(['d' => $prev, 'sel' => $view === 'day' ? $prev : null]) }}" aria-label="Previous">{{ icon('left') }}</a><div class="strong">{{ $title }} · {{ $p->name }} ({{ $p->total_stock }} total)</div><a class="btn btn-sm btn-icon" href="{{ $q(['d' => $next, 'sel' => $view === 'day' ? $next : null]) }}" aria-label="Next">{{ icon('right') }}</a></div>
    @if($view === 'day')
      @include('supplier._day', ['d' => $sel, 'b' => $selB, 'bookings' => $bookings])
    @else
      <div class="icals {{ $view === 'week' ? 'wk-view' : '' }}">
        @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $h)<div class="h">{{ $h }}</div>@endforeach
        @foreach($cells as $c)
          @php($b = $c['b'])
          @php($cls = $b['available'] === 0 ? 'none' : ($b['available'] < $b['total'] * 0.25 ? 'low' : 'ok'))
          <a class="c {{ $c['in'] ? '' : 'out' }} {{ $c['d'] === $sel ? 'sel' : '' }} {{ $c['d'] === today_iso() ? 'today' : '' }}" href="{{ $q(['sel' => $c['d']]) }}#dayInfo" style="{{ $c['in'] ? 'color:inherit;' : '' }}text-decoration:none" aria-label="{{ fmt_date($c['d']) }}: {{ $b['available'] }} free">
            <div class="dn"><span><span class="wd">{{ date('D', strtotime($c['d'])) }} </span>{{ (int) substr($c['d'], 8) }}</span>@if($c['d'] === today_iso())<span class="badge b-purple plain tday" style="padding:0 6px">Today</span>@endif</div>
            <div class="avail-pill {{ $cls }}">{{ $b['available'] }}<span class="lbl"> free</span></div>
            <div class="bars">
              @if($b['reserved'])<div class="bar res" title="{{ $b['reserved'] }} reserved">{{ $b['reserved'] }} reserved</div>@endif
              @if($b['pending'])<div class="bar pen" title="{{ $b['pending'] }} pending">{{ $b['pending'] }} pending</div>@endif
              @if($b['maintenance'] + $b['setaside'])<div class="bar mnt" title="{{ $b['maintenance'] + $b['setaside'] }} maintenance / set aside">{{ $b['maintenance'] + $b['setaside'] }} maint./aside</div>@endif
              @if($c['ret'])<div class="bar ret" title="{{ $c['ret'] }} returns">{{ $c['ret'] }} return{{ $c['ret'] > 1 ? 's' : '' }}</div>@endif
            </div>
          </a>
        @endforeach
      </div>
    @endif
    <div class="legend mt-2"><span><i style="background:var(--green-50)"></i>Plenty free</span><span><i style="background:var(--gold-50)"></i>Low (&lt;25%)</span><span><i style="background:var(--red-50)"></i>Fully booked</span><span><i style="background:var(--purple-50)"></i>Accepted reservations</span><span><i style="background:var(--gold-50);border:1px dashed #B45309"></i>Pending requests</span><span><i style="background:#F1EEF4"></i>Maintenance</span><span><i style="background:var(--teal-50)"></i>Returns</span></div>
  </div>
  <aside class="card" id="dayInfo" style="scroll-margin-top:80px">@include('supplier._day', ['d' => $sel, 'b' => $selB, 'bookings' => $bookings])</aside>
</div>
@push('head')<style>@media (max-width:1024px){.cal-layout{grid-template-columns:1fr!important}}</style>@endpush
@endif
@endsection

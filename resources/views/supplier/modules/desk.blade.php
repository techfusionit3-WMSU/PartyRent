@extends('layouts.dash', ['title' => 'Front Desk', 'nav' => 'desk'])

{{-- Front Desk module home: everything that needs an answer to a customer. --}}
@section('content')
<div class="page-head"><div><h1>Front Desk</h1><p>{{ $sup->name }} · requests, messages and problems that need an answer, {{ fmt_date($T) }}.</p></div>
  <a class="btn btn-primary" href="{{ route('supplier.requests') }}">{{ icon('list') }}All requests</a></div>
@include('partials.guides', ['welcome' => \App\Domain\Guides::welcome(auth()->user(), 'desk')])
@include('partials.needs-you', ['tasks' => \App\Domain\Tasks::forModule(auth()->user(), 'desk')])
<div class="stats">
  @include('partials.stat', ['label' => 'New requests', 'value' => $pend->count(), 'sub' => $pend->count() ? 'waiting for an answer' : 'all caught up', 'icon' => 'clock', 'color' => 'gold', 'href' => route('supplier.requests')])
  @include('partials.stat', ['label' => 'Unread messages', 'value' => $unread->count(), 'sub' => $unread->count() ? 'customers waiting for a reply' : 'no new messages', 'icon' => 'message', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'Open cases', 'value' => $cases->count(), 'sub' => $cases->count() ? 'problems to settle' : 'none open', 'icon' => 'alert', 'color' => 'red', 'href' => route('supplier.shortfalls')])
  @include('partials.stat', ['label' => 'Next 7 days', 'value' => $soon->count(), 'sub' => 'bookings going out', 'icon' => 'calendar', 'color' => 'purple', 'href' => route('supplier.schedule')])
</div>

<div class="grid grid-2 mt-2">
  <div class="card"><div class="card-head"><h3>Requests to answer</h3><span class="badge b-gold">{{ $pend->count() }}</span></div>
    @forelse($pend as $r)
      <a href="{{ route('supplier.requests.show', $r) }}" class="card card-flat mb-1" style="display:block;padding:12px;color:inherit;text-decoration:none">
        <div class="row between"><b>{{ $r->code }}</b><span class="small muted">sent {{ time_ago($r->created_at) }}</span></div>
        <div class="small">{{ $r->event_name }} · {{ $r->customer->name }} · {{ fmt_range($r->start_date, $r->end_date) }}</div>
        @if(in_array($r->id, $conflicts, true))<div class="small" style="color:var(--red);font-weight:600">{{ icon('alert') }} Not enough stock for all items: check before accepting</div>@endif
      </a>
    @empty <p class="small muted">No requests waiting.</p> @endforelse
  </div>
  <div>
    <div class="card mb-2"><div class="card-head"><h3>Customers waiting for a reply</h3></div>
      @forelse($unread as $r)
        <a href="{{ route('supplier.requests.show', $r) }}#chat" class="row between" style="padding:8px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><span><b>{{ $r->code }}</b> · {{ $r->customer->name }}</span><span class="badge b-sky">New message</span></a>
      @empty <p class="small muted">No unread messages.</p> @endforelse
    </div>
    @if($plans->isNotEmpty())
      <div class="card mb-2"><div class="card-head"><h3>Installment plans to review</h3></div>
        @foreach($plans as $r)<a href="{{ route('supplier.requests.show', $r) }}" class="row between" style="padding:8px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><span><b>{{ $r->code }}</b> · {{ $r->customer->name }}</span><span class="badge b-gold">Proposed</span></a>@endforeach
      </div>
    @endif
    <div class="card"><div class="card-head"><h3>Open problems & cases</h3></div>
      @forelse($cases as $c)
        <a href="{{ route('cases.show', $c) }}" class="row between" style="padding:8px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><span class="small"><b>{{ $c->code ?? ('#'.$c->id) }}</b> · {{ ucfirst(str_replace('_', ' ', $c->status)) }}</span><span class="small muted">{{ time_ago($c->created_at) }}</span></a>
      @empty <p class="small muted">No open cases.</p> @endforelse
    </div>
  </div>
</div>
@endsection

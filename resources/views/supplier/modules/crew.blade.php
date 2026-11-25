@extends('layouts.dash', ['title' => 'My Jobs', 'nav' => 'crew'])

{{-- Field Crew module home, phone-first: big buttons, one tap to call or open the map, and the next step right on the card. --}}
@php($me = auth()->user())
@php($canStep = $me->orgCan('fulfillment.update'))
@php($canInspect = $me->orgCan('returns.inspect'))
@section('content')
<div class="page-head"><div><h1>{{ __('My Jobs') }}</h1><p>{{ $sup->name }} · {{ __('deliveries, pickups and returns. Do the next step right on the card.') }}</p></div></div>
@include('partials.guides', ['welcome' => \App\Domain\Guides::welcome($me, 'crew')])

@foreach([[__('Late'), $late, 'b-red', __('These should have been done already.')], [__('Needs you today').' · '.fmt_date($T), $today, 'b-pink', null], [__('Next 7 days'), $next, 'b-sky', null]] as $k => [$head, $list, $cls, $note])
  @continue($k === 0 && ! $list)
  <section class="mb-3" aria-label="{{ $head }}">
    <div class="row between mb-1"><h2 style="font-size:1.1rem;margin:0">{{ $head }}</h2><span class="badge {{ $cls }}">{{ count($list) }}</span></div>
    @if($note)<p class="small muted">{{ $note }}</p>@endif
    @forelse($list as $j)
      @php($r = $j['r'])
      @php($road = \App\Domain\Jobs::onTheRoad($j))
      @php($phone = preg_replace('/[^0-9+]/', '', (string) ($r->contact_phone ?: $r->customer->phone)))
      @php($steps = $canStep ? \App\Domain\Rentals::nextSteps($r) : [])
      <article class="job-card">
        <div class="row between wrap"><div><span class="badge {{ in_array($j['kind'], ['Return', 'Inspect return'], true) ? 'b-gold' : 'b-teal' }}">{{ __($j['kind']) }}</span> <b>{{ $r->code }}</b> <span class="small muted">· {{ fmt_day($j['d']) }}</span></div>@include('partials.badges', ['status' => $r->status])</div>
        <div class="mt-1"><b>{{ $r->contact_name ?: $r->customer->name }}</b> <span class="small muted">· {{ $r->event_name }}</span></div>
        <div class="small muted">{{ collect($r->lines)->map(fn ($l) => inv()->itemName($l))->implode(', ') }}</div>
        @if($r->branch && $r->supplier->locations()->count() > 1)<div class="small mt-1">{{ icon('store') }} {{ $road ? __('Delivered by') : __('Pick up at') }} <b>{{ $r->branch->name }}</b></div>@endif
        @if($road && $r->address)<div class="small mt-1">{{ icon('map') }} {{ $r->address }}</div>@elseif(! $road)<div class="small muted mt-1">{{ icon('store') }} {{ __('Customer comes to the shop') }}</div>@endif
        @if($r->instructions)<div class="small mt-1"><b>{{ __('General Instructions') }}:</b> {{ $r->instructions }}</div>@endif

        <div class="job-quick">
          @if($phone)<a class="btn" href="tel:{{ $phone }}">{{ icon('user') }}{{ __('Call') }}</a>@else<span></span>@endif
          @if($road && $r->address)<a class="btn" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($r->address) }}" target="_blank" rel="noopener">{{ icon('map') }}{{ __('Map') }}</a>@else<a class="btn" href="{{ route('supplier.requests.show', $r) }}">{{ icon('eye') }}{{ __('Details') }}</a>@endif
        </div>

        <div class="job-main">
          @php($shown = false)
          @foreach($steps as $st)
            @php($why = \App\Domain\Rentals::stepBlockers($r, $st))
            @if($st === 'ongoing')
              @php($shown = true)
              @if($why)
                <button class="btn" disabled>{{ $r->fulfillment === 'delivery' ? __('Mark delivered') : __('Mark handed over') }}</button><div class="small mt-1" style="color:var(--red)">{{ $why[0] }}</div>
              @else
                <form method="POST" action="{{ route('supplier.requests.advance', $r) }}" enctype="multipart/form-data" data-confirm="{{ __('The items are now with the customer. They are notified.') }}" data-confirm-title="{{ $r->fulfillment === 'delivery' ? __('Mark delivered') : __('Mark handed over') }}?" data-confirm-ok="{{ __('Yes, done') }}">@csrf<input type="hidden" name="to" value="ongoing">
                  <label class="small" for="rb{{ $r->id }}">{{ __('Received by (name)') }}</label>
                  <input class="input mb-1" id="rb{{ $r->id }}" name="receivedBy" required value="{{ $r->contact_name }}" autocomplete="off">
                  <label class="small" for="ph{{ $r->id }}">{{ __('Photo of the items (condition)') }}</label>
                  <input class="input mb-1" id="ph{{ $r->id }}" type="file" name="release_photos[]" accept="image/*" capture="environment" multiple>
                  <button class="btn btn-primary">{{ icon('check') }}{{ $r->fulfillment === 'delivery' ? __('Mark delivered') : __('Mark handed over') }}</button>
                </form>
              @endif
            @elseif(in_array($st, ['in_progress', 'ready_pickup', 'out_delivery', 'return_pending'], true))
              @php($shown = true)
              @php($label = ['in_progress' => __('Start preparing'), 'ready_pickup' => __('Ready for pickup'), 'out_delivery' => __('Out for delivery'), 'return_pending' => $r->fulfillment === 'delivery' ? __('Picked up from customer') : __('Customer returned the items')][$st])
              @if($why)
                <button class="btn" disabled>{{ $label }}</button><div class="small mt-1" style="color:var(--red)">{{ $why[0] }}</div>
              @else
                <form method="POST" action="{{ route('supplier.requests.advance', $r) }}" data-confirm="{{ __('Move :code to “:status”? The customer is notified.', ['code' => $r->code, 'status' => \App\Models\RentalRequest::statusLabel($st)]) }}" data-confirm-title="{{ $label }}?" data-confirm-ok="{{ __('Yes') }}">@csrf<input type="hidden" name="to" value="{{ $st }}"><button class="btn btn-primary">{{ icon('check') }}{{ $label }}</button></form>
              @endif
            @endif
          @endforeach
          @if(! $shown)
            @if($r->status === 'return_pending' && $canInspect)
              <a class="btn btn-primary" href="{{ route('supplier.requests.show', $r) }}#inspect">{{ icon('check') }}{{ __('Inspect returned items') }}</a>
            @else
              <a class="btn" href="{{ route('supplier.requests.show', $r) }}">{{ __('Open') }}</a>
            @endif
          @endif
        </div>
      </article>
    @empty
      <p class="small muted">{{ $k === 1 ? __('No jobs today. Check the next days below.') : __('No jobs.') }}</p>
    @endforelse
  </section>
@endforeach
@endsection

@extends('layouts.market', ['title' => 'Checkout', 'nav' => 'cart'])

{{-- Port of C.checkout (reference js/pages/customer-rent.js). State lives in public/js/checkout.js;
     prices and validation always come from the server (POST checkout.quote → Rentals::prepareOrder). --}}
@section('content')
<div data-leave-warn="always" data-leave-msg="Your rental request has not been sent yet. If you leave now, nothing is requested (your Rental List items stay saved)." hidden></div>
@php($direct = $direct ?? false)
@if(! $orders)
  @include('partials.empty', ['emoji' => '🧾', 'title' => 'Nothing to check out', 'text' => 'Select items in your Rental List first.', 'action' => '<a class="btn btn-primary" href="'.route('cart').'">Go to Rental List</a>'])
@else
@if($direct)
<div class="crumbs"><a href="javascript:history.back()">Back to the item</a> / Checkout</div>
<div class="page-head"><div><h1>Checkout</h1><p>Requesting <b>{{ $orders[0]['lines'][0]['name'] }}</b> from {{ $orders[0]['shop']['name'] }} only. Your Rental List is not changed.</p></div></div>
@else
<div class="crumbs"><a href="{{ route('cart') }}">Rental List</a> / Checkout</div>
<div class="page-head"><div><h1>Checkout</h1><p>{{ collect($orders)->sum(fn ($o) => count($o['lines'])) }} selected item(s) from your Rental List, <b>{{ count($orders) }} shop{{ count($orders) > 1 ? 's' : '' }}</b>. Each shop gets its own rental order, payment plan and payment schedule.</p></div></div>
@endif
@include('partials.period-banner')
@php($state = ['orders' => $orders, 'shared' => ['contactName' => $u->name, 'contactPhone' => $u->phone, 'instructions' => '']])
<form id="cf" novalidate class="grid grid-side"
  data-checkout='@json($state)'
  data-quote="{{ route('checkout.quote') }}" data-qty="{{ route('checkout.qty') }}" data-submit="{{ route('checkout.submit') }}" data-today="{{ today_iso() }}">
  <div class="stack">
    <div class="card"><h3>Contact details</h3><div class="form-grid">
      <div class="field"><label for="ev-cn">Contact person</label><input class="input" id="ev-cn" data-s="contactName" required value="{{ $u->name }}"></div>
      <div class="field"><label for="ev-cp">Contact number</label><input class="input" id="ev-cp" data-s="contactPhone" data-phone required value="{{ $u->phone }}"></div>
      <div class="field full"><label for="ev-in">Special instructions (optional)</label><textarea class="input" id="ev-in" data-s="instructions" rows="2" placeholder="Setup location, gate codes…"></textarea></div>
    </div></div>
    <div class="stack" id="shops"></div>
  </div>
  <aside class="card sticky-side"><h3>Order summary</h3><div id="sum"><div class="page-loading"><div class="spinner"></div></div></div>
    <label class="check mt-2"><input type="checkbox" id="agree"> I agree to each shop's cancellation, return and rental policies.</label>
    <button type="button" class="btn btn-primary btn-lg btn-block mt-1" id="submitBtn">Submit {{ count($orders) > 1 ? count($orders).' rental requests' : 'rental request' }}</button>
    <p class="small muted mt-1 mb-0">Requests stay <b>Pending</b> until each supplier reviews them. Payment deadlines start when a supplier accepts. {{ $direct ? '' : 'Unselected items stay in your Rental List.' }}</p>
  </aside>
</form>
@include('partials.leaflet')
<script src="{{ asset('js/checkout.js') }}?v={{ filemtime(public_path('js/checkout.js')) }}" defer></script>
@endif
@endsection

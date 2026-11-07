@extends('layouts.market', ['title' => $k->name, 'nav' => 'packages'])

@section('content')
<div class="crumbs"><a href="{{ route('packages.index') }}">Packages</a> / {{ $k->name }}</div>
<div class="pd">
  <div>
    <div class="pd-gallery"><div class="main">@include('partials.img', ['item' => $k])</div></div>
    <div class="card mt-2"><h3>Package availability</h3><p class="small muted">Based on the stock of every included item.</p><div data-calendar data-kind="package" data-id="{{ $k->id }}" data-month="{{ substr($start, 0, 7) }}" data-need="1"></div></div>
  </div>
  <div data-rentbox data-type="package" data-max-days="{{ setting('maxRentalDays', 30) }}">
    <span class="badge b-pink plain">{{ $k->emoji }} Package</span>@foreach($k->events ?? [] as $e) <span class="badge b-gray plain">{{ $e }}</span>@endforeach
    <h1 class="mt-1">{{ $k->name }}</h1>
    <p>{{ $k->description }}</p>
    <div><span class="price-lg">{{ money($k->price) }}</span> <span class="muted">/ package / day</span>@if($v['savings'] > 0) <span class="badge b-gold">Save {{ money($v['savings']) }} vs. renting separately</span>@endif</div>
    <div class="card mt-2 pad-0"><div style="padding:14px 16px;border-bottom:1px solid var(--line)" class="strong">Included items</div>
      @foreach($k->items as $it)@php($ip = inv()->product($it['product_id']))@if($ip)
        <div class="row" style="padding:10px 16px;border-bottom:1px solid var(--line)">@include('partials.thumb', ['item' => $ip, 'cls' => 'sm'])<div class="grow"><a href="{{ route('products.show', $ip) }}">{{ $ip->name }}</a></div><b>× {{ $it['qty'] }}</b></div>
      @endif @endforeach
      <div class="small muted" style="padding:10px 16px">{{ $k->conditions }}</div>
    </div>
    <form class="card soft mt-2" method="POST" action="{{ route('cart.add') }}">
      @csrf
      <input type="hidden" name="type" value="package"><input type="hidden" name="ref_id" value="{{ $k->id }}"><input type="hidden" name="days" id="daysInput" value="{{ json_encode($days) }}">
      <div class="form-grid"><div class="field"><label for="ps">Start date</label><input type="date" class="input" id="ps" min="{{ today_iso() }}" value="{{ $start }}"></div><div class="field"><label for="pe">End date</label><input type="date" class="input" id="pe" min="{{ today_iso() }}" value="{{ $end }}"></div></div>
      <div data-scheduler data-type="package" data-ref="{{ $k->id }}" data-days='@json((object) $days)' data-siblings='@json($siblings)' data-sync="#daysInput"></div>
      @if($own)
        <div class="alert alert-info mt-2">{{ icon('info') }}<div>This is your own package.</div></div>
      @else
        <div class="row wrap mt-2"><button class="btn btn-outline btn-lg grow" name="then" value="list" data-rent-submit>{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout" data-rent-submit>Request Rental</button></div>
      @endif
    </form>
    <div class="card mt-2"><div class="row"><div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div><div class="grow"><a class="strong" href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->city }} · {{ $sup->fulfillmentLabel() }}</div></div></div></div>
  </div>
</div>
@endsection

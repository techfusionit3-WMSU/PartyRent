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
    @if($k->tier_group)
      @php($tiers = \App\Models\Package::where('supplier_id', $k->supplier_id)->where('tier_group', $k->tier_group)->where('active', true)->where('is_draft', false)->get()->sortBy(fn ($t) => \App\Domain\Packages::headlinePrice(\App\Domain\Packages::def($t))))
      @if($tiers->count() > 1)<div class="tabs mb-1" aria-label="{{ $k->tier_group }} tiers">@foreach($tiers as $t)<a class="tab {{ $t->id === $k->id ? 'active' : '' }}" href="{{ route('packages.show', $t) }}">{{ $t->tier_label ?: $t->name }}<span class="count">{{ money_short(\App\Domain\Packages::headlinePrice(\App\Domain\Packages::def($t))) }}</span></a>@endforeach</div>@endif
    @endif
    <p>{{ $k->description }}</p>
    <div><span class="price-lg" data-pkg-headline>{{ money($pp['package']) }}</span> <span class="muted">/ package{{ $pp['once'] > 0 && $pp['perDay'] <= 0 ? '' : ' / day' }}</span>@if($pp['savings'] > 0) <span class="badge b-gold">Save {{ money($pp['savings']) }} vs. getting it separately</span>@endif</div>
    @if($lead)<div class="alert alert-info mt-1">{{ icon('info') }}<div>Contains made-to-order items: book at least <b data-pkg-lead>{{ $lead }}</b> day(s) before your event.</div></div>@endif
    <div class="card mt-2 pad-0" data-pkg-config data-package="{{ $k->id }}" data-quote="{{ route('api.package-quote') }}">
      <div style="padding:14px 16px;border-bottom:1px solid var(--line)" class="strong">{{ ! array_filter($pp['rows'], fn ($r) => $r['group'] !== 'included') && ! collect($def['components'])->contains(fn ($c) => in_array($c['kind'], ['choice', 'addon'], true)) && empty($def['minGuests']) ? 'Included items' : "What's in the package" }}</div>
      @include('market._package-contents', ['def' => $def, 'pp' => $pp, 'interactive' => ! $own])
      @if($k->conditions)<div class="small muted" style="padding:10px 16px;border-top:1px solid var(--line)">{{ $k->conditions }}</div>@endif
    </div>
    <form class="card soft mt-2" method="POST" action="{{ route('cart.add') }}">
      @csrf
      <input type="hidden" name="type" value="package"><input type="hidden" name="ref_id" value="{{ $k->id }}"><input type="hidden" name="days" id="daysInput" value="{{ json_encode($days) }}"><input type="hidden" name="sel" id="selInput" value="{{ json_encode($pp['sel']) }}">
      <div class="form-grid"><div class="field"><label for="ps">Start date</label><input type="date" class="input" id="ps" min="{{ add_days(today_iso(), $lead) }}" value="{{ $start }}"></div><div class="field"><label for="pe">End date</label><input type="date" class="input" id="pe" min="{{ add_days(today_iso(), $lead) }}" value="{{ $end }}"></div>@include('partials.rent-times', ['sup' => $k->supplier, 'item' => $k, 'part' => 'selects'])</div>
      @include('partials.rent-times', ['sup' => $k->supplier, 'item' => $k, 'part' => 'line'])
      @if($def['stages'])
        <div class="mb-2" id="stageBox"><div class="label mb-1">Your event schedule</div>
          @foreach($def['stages'] as $i => $st)
            <div class="form-grid" style="grid-template-columns:1fr 2fr"><div class="field"><label class="small" for="stT{{ $i }}">{{ $st['name'] }} · time</label><input class="input input-sm" type="time" id="stT{{ $i }}" data-stage-time="{{ $i }}"></div><div class="field"><label class="small" for="stV{{ $i }}">Venue</label><input class="input input-sm" id="stV{{ $i }}" data-stage-venue="{{ $i }}" maxlength="160" placeholder="{{ $st['hint'] ?: 'e.g. church, hall, home address' }}"></div></div>
          @endforeach
          <div class="hint">You can leave these blank and agree on them with the supplier later.</div>
        </div>
      @endif
      <div data-scheduler data-type="package" data-ref="{{ $k->id }}" data-sel='@json($pp['sel'])' data-days='@json((object) $days)' data-siblings='@json($siblings)' data-sync="#daysInput"></div>
      @if($own)
        <div class="alert alert-info mt-2">{{ icon('info') }}<div>This is your own package.</div></div>
      @else
        <div class="row wrap mt-2"><button class="btn btn-outline btn-lg grow" name="then" value="list" data-rent-submit>{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout" data-rent-submit>Request Rental</button></div>
      @endif
    </form>
    <div class="card mt-2"><div class="row"><div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div><div class="grow"><a class="strong" href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->city }} · {{ $sup->fulfillmentLabel() }}</div></div></div></div>
  </div>
</div>
<div class="section">@include('partials.reviews-block', ['reviews' => $reviews, 'showItem' => false, 'title' => 'Customer reviews', 'emptyText' => 'No one has reviewed this package yet.'])</div>
@if($def['stages'])
@push('scripts')
<script>
// Stage times and venues ride along in the package selection (sel.stages).
(function () {
  var box = document.getElementById('stageBox'); if (!box) return;
  box.closest('form').addEventListener('submit', function () {
    var input = document.getElementById('selInput'), sel = {};
    try { sel = JSON.parse(input.value || '{}') || {}; } catch (e) {}
    sel.stages = Array.prototype.map.call(box.querySelectorAll('[data-stage-time]'), function (t) {
      var i = t.dataset.stageTime; return { time: t.value, venue: box.querySelector('[data-stage-venue="' + i + '"]').value };
    });
    input.value = JSON.stringify(sel);
  }, true);
})();
</script>
@endpush
@endif
@endsection

@extends('layouts.market', ['title' => $p->name, 'nav' => 'browse', 'alternativesUrl' => route('products.alternatives', $p)])

@section('content')
<div class="crumbs"><a href="{{ route('browse') }}">Browse</a> / <a href="{{ route('browse', ['category' => $cat->id]) }}">{{ $cat->name }}</a> / {{ $p->name }}</div>
<div class="pd">
  <div class="pd-gallery">
    <div class="main">@include('partials.img', ['item' => $p])</div>
    @if(count($p->images ?? []) > 1)<div class="thumbs">@foreach($p->images as $im)<img src="{{ asset('storage/'.$im) }}" alt="Photo of {{ $p->name }}">@endforeach</div>@endif
    <div class="card mt-2"><h3>Availability calendar</h3><div data-calendar data-kind="item" data-id="{{ $p->id }}" data-month="{{ substr($start, 0, 7) }}" data-need="{{ $qty }}"></div></div>
  </div>

  <div @unless($p->isHourly()) data-rentbox @endunless data-type="item" data-max-days="{{ setting('maxRentalDays', 30) }}">
    <span class="badge b-purple plain">{{ $cat->name }}</span> <span class="badge b-gray plain">Condition: {{ $p->condition }}</span>
    <h1 class="mt-1">{{ $p->name }}</h1>
    <div class="row wrap small muted">@if($reviews->count())<span class="stars">{{ stars($p->rating) }}</span><a href="#reviews">{{ number_format($p->rating, 1) }} · {{ $reviews->count() }} {{ $reviews->count() === 1 ? 'review' : 'reviews' }}</a>@else<span>No reviews yet</span>@endif<span>· {{ $p->rent_count }} rentals</span></div>
    <div class="mt-2"><span class="price-lg">{{ money($p->basePrice()) }}</span> <span class="muted">/ {{ $p->unit }}</span></div>
    @if(! $p->isSale() && $p->replacement_value)<div class="small muted mt-1">{{ icon('info') }} Replacement value: <b>{{ money($p->replacement_value) }}</b> per piece. This is the most you would pay if one is lost or destroyed.</div>@endif
    @if(count($p->tiers) > 1)
      <div class="mt-1"><div class="small strong mb-1">Quantity-based pricing ({{ $p->isHourly() ? 'per hour' : 'per day' }})</div><div class="tiers">
        @foreach(\App\Domain\Pricing::sortedTiers($p) as $t)<div class="tier" data-tier data-min="{{ $t['min'] }}" data-max="{{ $t['max'] }}"><b>{{ money($t['price']) }}</b>{{ $t['min'] }}{{ $t['max'] ? '–'.$t['max'] : '+' }} pcs</div>@endforeach
      </div></div>
    @endif
    <p class="mt-2">{{ $p->description }}</p>
    <div class="row wrap gap-sm mb-2">
      @foreach($p->colors ?? [] as $c)<span class="tag"><span class="swatch" title="{{ $c }}" style="background:{{ color_hex($c) }}"></span> {{ $c }}</span>@endforeach
      @foreach($p->themes ?? [] as $t)<span class="tag">{{ $t }}</span>@endforeach
    </div>

    @if($p->isHourly())
    {{-- Rented by the hour: one date, a start and end time, a quantity. --}}
    <form class="card soft" method="POST" action="{{ route('cart.add') }}" id="hourForm" data-slots="{{ route('api.slots') }}" data-id="{{ $p->id }}" data-tiers='@json(\App\Domain\Pricing::sortedTiers($p))' data-min-hours="{{ (float) $p->min_hours }}">
      @csrf
      <input type="hidden" name="type" value="item"><input type="hidden" name="ref_id" value="{{ $p->id }}"><input type="hidden" name="days" id="hDays" value="{}"><input type="hidden" name="from" id="hFromV"><input type="hidden" name="to" id="hToV">
      <div class="form-grid">
        <div class="field"><label for="hDate">Date</label><input class="input" type="date" id="hDate" min="{{ today_iso() }}" value="{{ $start }}" required></div>
        <div class="field"><label for="hQty">Quantity</label><input class="input" type="number" id="hQty" min="1" max="{{ $p->total_stock }}" value="1" required></div>
        <div class="field"><label for="hFrom">Start time</label><input class="input" type="time" id="hFrom" step="900" value="{{ old('from', '14:00') }}" required></div>
        <div class="field"><label for="hTo">End time</label><input class="input" type="time" id="hTo" step="900" value="{{ old('to', sprintf('%02d:00', min(24, 14 + (int) ceil((float) $p->min_hours)))) }}" required></div>
      </div>
      <p class="small muted mb-1">Minimum {{ rtrim(rtrim(number_format((float) $p->min_hours, 1), '0'), '.') }} hours. The rental partner keeps {{ (int) $p->buffer_min }} minutes free before and after each booking for set-up and travel.</p>
      <div id="hBusy" class="small mb-1"></div>
      <div class="sum-line total"><span>Estimated rental <span class="small muted" id="hHours"></span></span><span class="price" id="hEst"></span></div>
      @if($own)
        <div class="alert alert-info">{{ icon('info') }}<div>This is your own listing. <a href="{{ route('supplier.products.edit', $p) }}">Edit in Rental Partner Mode</a></div></div>
      @else
        <div class="row wrap mt-1"><button class="btn btn-outline btn-lg grow" name="then" value="list">{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout">Request Rental</button></div>
      @endif
      <p class="small muted mt-1 mb-0">The time is only held once the rental partner accepts your request.</p>
    </form>
    @push('scripts')
    <script>
    (function () {
      var f = document.getElementById('hourForm'); if (!f) return;
      var $ = function (id) { return document.getElementById(id); };
      var tiers = JSON.parse(f.dataset.tiers || '[]'), minH = +f.dataset.minHours || 0, timer;
      function mins(v) { var m = /^(\d{2}):(\d{2})$/.exec(v || ''); return m ? +m[1] * 60 + +m[2] : null; }
      function price(q) { var t = tiers.filter(function (t) { return q >= t.min && (!t.max || q <= t.max); })[0] || tiers[0]; return t ? +t.price : 0; }
      function peso(n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { maximumFractionDigits: 2 }); }
      function draw() {
        var q = Math.max(1, +$('hQty').value || 1), a = mins($('hFrom').value), b = mins($('hTo').value);
        if (b === 0) b = 1440; // 12:00 AM end = midnight
        var h = a !== null && b !== null && b > a ? (b - a) / 60 : 0;
        $('hFromV').value = $('hFrom').value; $('hToV').value = $('hTo').value === '00:00' ? '24:00' : $('hTo').value;
        $('hDays').value = JSON.stringify($('hDate').value ? (function () { var o = {}; o[$('hDate').value] = q; return o; })() : {});
        $('hHours').textContent = h ? '(' + h + ' h × ' + q + ')' : '';
        $('hEst').textContent = h ? peso(price(q) * h * q) : '—';
        clearTimeout(timer); timer = setTimeout(check, 250);
        if (h && h < minH) $('hHours').textContent += ' · minimum ' + minH + ' h';
      }
      function check() {
        if (!$('hDate').value) return;
        fetch(f.dataset.slots + '?id=' + f.dataset.id + '&date=' + $('hDate').value + '&from=' + $('hFromV').value + '&to=' + $('hToV').value, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); }).then(function (d) {
            var html = d.busy.length ? 'Already booked that day: ' + d.busy.map(function (s) { return '<span class="badge b-gray plain">' + s.label + ' · ' + s.qty + '</span>'; }).join(' ') : '<span style="color:var(--green)">No bookings yet on this date.</span>';
            if (d.available !== null) html += '<div class="mt-1 strong" style="color:' + (d.available >= (+$('hQty').value || 1) ? 'var(--green)' : 'var(--red)') + '">' + d.available + ' free for your time</div>';
            $('hBusy').innerHTML = html;
          }).catch(function () {});
      }
      f.addEventListener('submit', draw);
      ['hDate', 'hQty', 'hFrom', 'hTo'].forEach(function (id) { $(id).addEventListener('input', draw); });
      draw();
    })();
    </script>
    @endpush
    @else
    <form class="card soft" method="POST" action="{{ route('cart.add') }}">
      @csrf
      <input type="hidden" name="type" value="item"><input type="hidden" name="ref_id" value="{{ $p->id }}"><input type="hidden" name="days" id="daysInput" value="{{ json_encode($days) }}">
      <div class="form-grid"><div class="field"><label for="ps">Start date</label><input class="input" type="date" id="ps" min="{{ today_iso() }}" value="{{ $start }}"></div><div class="field"><label for="pe">End date</label><input class="input" type="date" id="pe" min="{{ today_iso() }}" value="{{ $end }}"></div></div>
      <div class="row wrap between">
        <div><div class="label mb-1">Quantity per day</div><div class="qty" id="qbox"><button type="button" data-d aria-label="Decrease">−</button><input type="number" id="pq" min="1" value="{{ $qty }}" aria-label="Quantity"><button type="button" data-i aria-label="Increase">+</button></div></div>
        <div class="right"><div class="small muted">Stock: {{ $p->total_stock }} total</div><div id="availTxt"></div></div>
      </div>
      <details class="mt-2" id="perDay"><summary class="strong" style="cursor:pointer">Need different quantities per day? Customize each date</summary>
        <div class="mt-1" data-scheduler data-type="item" data-ref="{{ $p->id }}" data-days='@json((object) $days)' data-siblings='@json($siblings)' data-sync="#daysInput"></div>
      </details>
      <div id="altBox"></div>
      <div class="sum-line total"><span>Estimated rental</span><span class="price" id="est"></span></div>
      @if($own)
        <div class="alert alert-info">{{ icon('info') }}<div>This is your own listing. <a href="{{ route('supplier.products.edit', $p) }}">Edit in Rental Partner Mode</a></div></div>
      @else
        <div class="row wrap mt-1"><button class="btn btn-outline btn-lg grow" name="then" value="list" data-rent-submit>{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout" data-rent-submit>Request Rental</button></div>
      @endif
      <p class="small muted mt-1 mb-0">Submitting a request does not reserve the items until the rental partner accepts it.</p>
    </form>
    @endif

    <div class="card mt-2">
      <div class="row"><div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div><div class="grow"><a class="strong" href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->city }} · ★ {{ rating_text($sup) }}</div></div><a class="btn btn-sm" href="{{ route('suppliers.show', $sup) }}">View Rental Partner Profile</a></div>
      <hr>
      <dl class="kv">
        @php($myCity = customer_city())
        <dt>Delivery / pickup</dt><dd>{{ $sup->fulfillmentLabel() }}{{ ! $p->delivery && $sup->offersDelivery() ? ' (this item: pickup only)' : '' }}{{ $sup->offersDelivery() ? ' · '.$sup->deliverySummary() : '' }}
          @if($myCity && $sup->offersDelivery() && $p->delivery)
            @php($reach = $sup->deliveryTo($myCity))
            @if($reach['ok'] && \App\Domain\Pricing::freeShipActive($sup))<div class="mb-1">@include('partials.free-ship-badge', ['s' => $sup]) <span class="small muted">Up to {{ money(setting('freeShipCap')) }} off delivery on {{ money(setting('freeShipMinSpend')) }}+ orders</span></div>@endif
            <div class="small" style="color:{{ $reach['ok'] ? 'var(--green)' : '#B45309' }}">{{ $reach['ok'] ? 'Delivers to '.\App\Domain\Locations::name($myCity).' from '.money($reach['fee']) : ($sup->fulfillment === 'both' ? 'Pickup only in your area' : $reach['error']) }}</div>
          @elseif(! $myCity && $sup->offersDelivery())
            <div class="small muted">@auth<a href="{{ route('profile') }}">Set your city</a>@else Log in and set your city @endauth to see the delivery fee.</div>
          @endif
        </dd>
        @if($p->handling_fee > 0)<dt>Handling</dt><dd>{{ money($p->handling_fee) }} {{ $p->handling_per === 'unit' ? 'per unit' : 'per order' }} when delivered</dd>@endif
        <dt>Payment arrangements</dt><dd>{{ collect($sup->arrangements)->map(fn ($a) => \App\Models\Payment::ARR_LABEL[$a])->implode(', ') }}</dd>
        <dt>Payment methods</dt><dd>{{ collect($sup->methods)->map(fn ($m) => \App\Models\Payment::METHOD_LABEL[$m] ?? $m)->implode(', ') }}</dd>
        <dt>Security deposit</dt><dd>{{ $sup->depositLabel() }} (refundable)</dd>
        <dt>Cancellation</dt><dd>{{ $sup->pol('cancellation') }}</dd>
        <dt>Returns</dt><dd>{{ $sup->pol('returns') }}</dd>
      </dl>
    </div>
  </div>
</div>

<div class="section">@include('partials.reviews-block', ['reviews' => $reviews, 'showItem' => false, 'title' => 'Customer reviews', 'emptyText' => 'Be the first: rent this item and share how it went — photos and videos welcome.'])</div>
@if($related->isNotEmpty())@include('market._section', ['title' => 'More from '.$sup->name, 'items' => $related])@endif
@if($similar->isNotEmpty())@include('market._section', ['title' => 'Similar items from other shops', 'items' => $similar])@endif
@endsection

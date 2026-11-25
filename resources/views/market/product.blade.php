@extends('layouts.market', ['title' => $p->name, 'nav' => 'browse', 'alternativesUrl' => route('products.alternatives', $p), 'bodyClass' => 'pdx-page'])

{{--
  Product listing page, TikTok-Shop style (change list C3) with separate layouts:
  web = thumbnail rail + large photo | buy column (price banner, title, rent box), then shop card and detail cards;
  mobile = swipeable full-width photos with a counter, price banner, title, rent box, sections, and a sticky
  action bar (shop · Add to Rental List · Request Rental) in place of the bottom nav.
  The quantity-price table is replaced by one plain bulk discount line (Pricing::bulkLine).
--}}
@section('content')
@php($imgs = $p->images ?? [])
@php($bulk = \App\Domain\Pricing::bulkLine($p))
<div class="crumbs pdx-crumbs"><a href="{{ route('home') }}">Browse</a> / <a href="{{ route('home', ['category' => $cat->id]) }}">{{ $cat->name }}</a> / {{ $p->name }}</div>
<div class="pdx">
  <div class="pdx-media">
    @if(count($imgs) > 1)<div class="pdx-thumbs" role="tablist" aria-label="Photos">@foreach($imgs as $i => $im)<button type="button" class="{{ $i ? '' : 'on' }}" data-pdx-go="{{ $i }}" aria-label="Photo {{ $i + 1 }}"><img src="{{ asset('storage/'.$im) }}" alt=""></button>@endforeach</div>@endif
    <div class="pdx-stagecol"><div class="pdx-stage">
      <div class="pdx-track" id="pdxTrack">
        @forelse($imgs as $im)<div class="pdx-slide"><img src="{{ asset('storage/'.$im) }}" alt="Photo of {{ $p->name }}"></div>
        @empty<div class="pdx-slide">@include('partials.img', ['item' => $p, 'artSize' => '42%'])</div>@endforelse
      </div>
      @if(count($imgs) > 1)<span class="pdx-count" id="pdxCount">1/{{ count($imgs) }}</span>@endif
    </div>
    <a class="card pdx-shop" href="{{ route('suppliers.show', $sup) }}">
  <div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div>
  <div class="grow" style="min-width:0"><div class="strong ellipsis">{{ $sup->name }}</div><div class="small muted">{{ $sup->city }} · ★ {{ rating_text($sup) }}</div></div>
  <span class="btn btn-sm">View Supplier Profile</span>
</a>
    </div>
  </div>

  <div class="pdx-buy" @unless($p->isHourly()) data-rentbox @endunless data-type="item" data-max-days="{{ setting('maxRentalDays', 30) }}">
    <div class="pdx-price">
      <div><span class="pdx-amt">{{ money($p->basePrice()) }}</span> <span class="pdx-unit">/ {{ $p->unit }}</span></div>
      @if($bulk)<div class="pdx-bulk">{{ icon('tag', 'width:14px;height:14px;vertical-align:-2px') }} {{ $bulk }}</div>@endif
      @foreach(\App\Domain\Discounts::running($p->supplier_id, today_iso())->filter(fn ($d) => $d->covers($p)) as $promo)<div class="pdx-bulk">{{ icon('sparkle', 'width:14px;height:14px;vertical-align:-2px') }} {{ $promo->name }}: {{ $promo->label() }}{{ $promo->min_qty ? ' for '.$promo->min_qty.'+ pieces' : '' }}{{ $promo->ends_on ? ' · until '.fmt_date($promo->ends_on->format('Y-m-d')) : '' }}</div>@endforeach
    </div>
    <h1 class="pdx-title">{{ $p->name }}</h1>
    <div class="pdx-meta">@if($reviews->count())<span class="stars">{{ stars($p->rating) }}</span> <a href="#reviews">{{ number_format($p->rating, 1) }} ({{ $reviews->count() }})</a>@else<span>No reviews yet</span>@endif<span class="dotsep">{{ $p->rent_count }} rented</span></div>
    <div class="row wrap gap-sm mt-1">
      <span class="badge b-purple plain">{{ $cat->name }}</span><span class="badge b-gray plain">{{ \App\Domain\Damage::conditionLabel($p->condition) }}</span>
      @if(! $p->isSale())<span class="badge {{ $p->max_rental_days ? 'b-purple' : 'b-gray' }} plain">{{ icon('clock', 'width:13px;height:13px') }} {{ $p->max_rental_days ? 'Maximum Rental: '.$p->max_rental_days.' Day'.($p->max_rental_days > 1 ? 's' : '') : 'No maximum days' }}</span>@if($p->min_hours > 0)<span class="badge b-gold plain">{{ icon('clock', 'width:13px;height:13px') }} {{ \App\Domain\Slots::hourLimitLabel($p) }}</span>@endif @endif
      @if($p->delivery && $sup->offersDelivery())<span class="badge b-sky plain">{{ icon('truck', 'width:13px;height:13px') }} Delivery</span>@endif
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
      <p class="small muted mb-1">Minimum {{ rtrim(rtrim(number_format((float) $p->min_hours, 1), '0'), '.') }} hours. The supplier keeps {{ (int) $p->buffer_min }} minutes free before and after each booking for set-up and travel.</p>
      <div id="hBusy" class="small mb-1"></div>
      @include('partials.bulk-prices', ['p' => $p])
      @include('market._policies')
      <div class="sum-line total"><span>Estimated rental <span class="small muted" id="hHours"></span></span><span class="price" id="hEst"></span></div>
      @if($own)
        <div class="alert alert-info">{{ icon('info') }}<div>This is your own listing. <a href="{{ route('supplier.products.edit', $p) }}">Edit in Supplier Mode</a></div></div>
      @else
        <div class="row wrap mt-1 pdx-cta"><a class="btn btn-lg pdx-cta-shop" href="{{ route('suppliers.show', $sup) }}" aria-label="Visit {{ $sup->name }}">{{ icon('store') }}<span>Shop</span></a><button class="btn btn-outline btn-lg grow" name="then" value="list">{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout">Request Rental</button></div>
      @endif
      <p class="small muted mt-1 mb-0">The time is only held once the supplier accepts your request.</p>
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
        if (window.PR && window.PR.bulkApplied) window.PR.bulkApplied(f, [q]);
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
      @include('partials.rent-box', ['type' => 'item', 'ref' => $p->id, 'days' => $days, 'siblings' => $siblings, 'start' => $start, 'end' => $end, 'qty' => $qty, 'minDate' => today_iso(), 'sup' => $sup, 'item' => $p, 'sel' => null, 'stockText' => $nearAvail === null ? 'Stock: '.$p->total_stock.' total' : $nearAvail.' available near you', 'timed' => ! $p->isSale()])
      @include('partials.bulk-prices', ['p' => $p])
      <div id="altBox"></div>
      @include('market._policies')
      <div class="sum-line total"><span>Estimated rental</span><span class="price" id="est"></span></div>
      @if($own)
        <div class="alert alert-info">{{ icon('info') }}<div>This is your own listing. <a href="{{ route('supplier.products.edit', $p) }}">Edit in Supplier Mode</a></div></div>
      @else
        <div class="row wrap mt-1 pdx-cta"><a class="btn btn-lg pdx-cta-shop" href="{{ route('suppliers.show', $sup) }}" aria-label="Visit {{ $sup->name }}">{{ icon('store') }}<span>Shop</span></a><button class="btn btn-outline btn-lg grow" name="then" value="list" data-rent-submit>{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout" data-rent-submit>Request Rental</button></div>
      @endif
      <p class="small muted mt-1 mb-0">Nothing is charged when you send a request. Once the shop accepts, you pay within 24 hours to reserve the items.</p>
    </form>
    @endif
  </div>
</div>


<div class="pdx-sections">
  <section class="card"><h3>Description</h3>
    <p class="pdx-desc">{{ $p->description }}</p>
    <dl class="kv">
      <dt>Category</dt><dd>{{ $cat->name }}</dd>
      <dt>Condition</dt><dd>{{ \App\Domain\Damage::conditionLabel($p->condition) }}</dd>
      @if($p->colors)<dt>Colors</dt><dd><div class="row wrap gap-sm">@foreach($p->colors as $c)<span class="tag"><span class="swatch" title="{{ $c }}" style="background:{{ color_hex($c) }}"></span> {{ $c }}</span>@endforeach</div></dd>@endif
      @if($p->themes)<dt>Themes</dt><dd><div class="row wrap gap-sm">@foreach($p->themes as $t)<span class="tag">{{ $t }}</span>@endforeach</div></dd>@endif
      @if(! $p->isSale())
        <dt>Stock</dt><dd>
          @if($nearAvail === null)
            {{ $p->stockText($p->total_stock) }} in total
          @elseif($nearAvail > 0)
            <b>{{ $nearAvail }}</b> available{{ \App\Support\Near::label() ? ' near '.\App\Support\Near::label() : '' }} on {{ fmt_date($start) }}
          @else
            <span style="color:var(--red)">Not available{{ \App\Support\Near::label() ? ' near '.\App\Support\Near::label() : '' }} on {{ fmt_date($start) }}</span> · <a href="{{ route('near', ['back' => request()->fullUrl()]) }}">change place</a>
          @endif
        </dd>
      @endif
      @unless($p->isSale())
        {{-- damage fee: a possible charge only if the item comes back damaged or missing, never part of the rental price --}}
        @php($lia = \App\Domain\Damage::liability($p))
        @php($dt = \App\Domain\Damage::terms($p))
        <dt>Damage fee</dt><dd class="dmg-dd">
          @if($lia['max'] !== null)
            <div><b>Up to {{ money($lia['max']) }}</b> per {{ $p->unitWord() }} if lost or beyond repair{{ $lia['minor'] !== null ? ' · minor damage '.money($lia['minor']) : '' }} @if($dt && $dt['receipt'])<span class="badge b-green plain">Receipt on file</span>@endif</div>
          @else
            <div><b>No damage fee set</b> for this item.</div>
          @endif
          <div class="small muted">Not part of the rental price. You only pay if it comes back damaged or missing, after the shop checks it. You get {{ \App\Domain\Damage::REVIEW_HOURS }} hours to accept or dispute any charge; PartyRent decides disputes.</div>
          @if($dt && $lia['max'] !== null)
            <details class="small mt-1"><summary>How damage is charged</summary>
              @if($dt['minor'] !== null)<div><b>Minor</b> (cleaning, stain, small scratch): {{ money($dt['minor']) }} per {{ $p->unitWord() }}</div>@endif
              <div><b>Damaged</b>: the repair cost, never more than {{ money($dt['value']) }}</div>
              <div><b>Not working</b>: free if it is a normal fault; charged as a repair only if misused</div>
              <div><b>Lost or beyond repair</b>: {{ money($dt['value']) }} per {{ $p->unitWord() }}</div>
            </details>
          @elseif($lia['max'] !== null)
            <div class="small muted">Repairs are charged as a share of the item's value ({{ money($lia['value']) }}), set in the shop's damage policy.</div>
          @endif
        </dd>
      @endunless
      @if(! $p->isSale() && $p->rental_policy)<dt>Item rental policy</dt><dd style="white-space:pre-line">{{ $p->rental_policy }}</dd>@endif
    </dl>
  </section>

  <section class="card"><h3>Availability calendar</h3><div data-calendar data-kind="item" data-id="{{ $p->id }}" data-month="{{ substr($start, 0, 7) }}" data-need="{{ $qty }}"></div></section>
  <a class="card pdx-shop pdx-shop-phone" href="{{ route('suppliers.show', $sup) }}">
  <div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div>
  <div class="grow" style="min-width:0"><div class="strong ellipsis">{{ $sup->name }}</div><div class="small muted">{{ $sup->city }} · ★ {{ rating_text($sup) }}</div></div>
  <span class="btn btn-sm">View Supplier Profile</span>
</a>

</div>

@push('scripts')
<script>
// Photo gallery: swipe (scroll-snap) on mobile, thumbnail rail on web; counter follows the visible photo.
(function () {
  var tr = document.getElementById('pdxTrack'); if (!tr) return;
  var cnt = document.getElementById('pdxCount'), th = document.querySelectorAll('[data-pdx-go]'), n = tr.children.length;
  function at() { return Math.round(tr.scrollLeft / Math.max(1, tr.clientWidth)); }
  tr.addEventListener('scroll', function () { var i = at(); if (cnt) cnt.textContent = (i + 1) + '/' + n; th.forEach(function (b, j) { b.classList.toggle('on', j === i); }); }, { passive: true });
  th.forEach(function (b) { b.addEventListener('click', function () { tr.scrollTo({ left: +b.getAttribute('data-pdx-go') * tr.clientWidth, behavior: 'smooth' }); }); });
})();
</script>
@endpush

<div class="section">@include('partials.reviews-block', ['reviews' => $reviews, 'showItem' => false, 'title' => 'Customer reviews', 'emptyText' => 'Be the first: rent this item and share how it went — photos and videos welcome.'])</div>
@if($related->isNotEmpty())@include('market._section', ['title' => 'More from '.$sup->name, 'items' => $related])@endif
@if($similar->isNotEmpty())@include('market._section', ['title' => 'Similar items from other shops', 'items' => $similar])@endif
@endsection

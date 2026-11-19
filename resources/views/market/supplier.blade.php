@extends('layouts.market', ['title' => $s->name, 'nav' => 'suppliers'])

{{-- Port of C.supplier (reference js/pages/customer-discover.js) --}}
@section('content')
@php
    $pol = $s->policy ?? [];
    $ts = \App\Domain\Slots::settings($s);
    $biz = $s->isOrganization();
    $bp = (array) ($s->business_profile ?? []);
    $tabList = [['items', 'Products', $items->count()], ['packages', 'Packages', $pkgs->count()], ['policies', 'Policies & payments', null], ['reviews', 'Reviews', $reviews->count()]];
    if ($biz) { // an event company: event packages booked by quotation, no single-item rentals
        $evPkgs = $s->eventPackages()->where('active', true)->orderBy('price')->get();
        $set = $s->bizSettings();
        $tabList = [['packages', 'Event packages', $evPkgs->count()], ...($s->portfolio ? [['portfolio', 'Portfolio', count($s->portfolio)]] : []), ['policies', 'How booking works', null], ['reviews', 'Reviews', $reviews->count()]];
        if (! in_array($tab, ['packages', 'portfolio', 'policies', 'reviews'], true)) { $tab = 'packages'; }
    }
@endphp
<div class="crumbs"><a href="{{ route('suppliers.index') }}">Suppliers</a> / {{ $s->name }}</div>
@if($s->status !== 'approved')<div class="alert alert-warn mb-2">{{ icon('clock') }}<div>Preview only — your shop is <b>{{ $s->status }}</b> and is not visible to customers yet.</div></div>@endif
<div class="card" style="background:linear-gradient(135deg,{{ $s->color }}14,#fff){{ $biz && $s->cover_path ? ';padding-top:0;overflow:hidden' : '' }}">@if($biz && $s->cover_path)<div class="shop-cover mb-2" style="background-image:url('{{ asset('storage/'.$s->cover_path) }}')" role="img" aria-label="{{ $s->name }} cover photo"></div>@endif<div class="row wrap top">
  <div class="logo-tile" style="width:84px;height:84px;font-size:2.6rem;background:#fff;box-shadow:var(--shadow)">@if($s->logo_path)<img src="{{ asset('storage/'.$s->logo_path) }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px">@else{{ $s->logo }}@endif</div>
  <div class="grow"><h1 class="mb-0">{{ $s->name }}</h1><div class="muted">{{ $s->city }} · <span class="stars">{{ stars($s->rating) }}</span> {{ $s->rating ? rating_text($s) : 'New' }} ({{ $reviews->count() }} reviews)</div>
    <p class="mt-1 mb-1">{{ $s->description }}</p>
    @if($biz && $bp)
      @if($bp['events'] ?? [])<div class="small muted mb-0">Events we cater</div><div class="row wrap gap-sm mb-1">@foreach($bp['events'] as $e)<span class="badge b-pink plain">{{ $e }}</span>@endforeach</div>@endif
      @if($bp['offers'] ?? [])<div class="small muted mb-0">Services</div><div class="row wrap gap-sm mb-1">@foreach($bp['offers'] as $x)<span class="badge b-purple plain">{{ $x }}</span>@endforeach</div>@endif
      <p class="small mb-1">@if($bp['eventSize'] ?? null){{ icon('users', 'width:14px;vertical-align:-2px') }} {{ \App\Models\Supplier::EVENT_SIZES[$bp['eventSize']] ?? '' }}@endif @if($bp['packagesFrom'] ?? null) · Packages from <b>{{ money($bp['packagesFrom']) }}</b>@endif</p>
    @endif
    @if($biz)
      <div class="row wrap gap-sm">@if($set['serviceAreas'])<span class="badge b-teal">{{ icon('map', 'width:12px') }} Serves {{ implode(', ', array_slice($set['serviceAreas'], 0, 4)) }}{{ count($set['serviceAreas']) > 4 ? ' +'.(count($set['serviceAreas']) - 4) : '' }}</span>@endif<span class="badge b-gray plain">On-site setup & teardown</span></div>
      @unless($own)<a class="btn btn-primary mt-2" href="{{ route('events.create', $s) }}">{{ icon('send') }}Ask for a quotation</a>@endunless
    @else
    <div class="row wrap gap-sm"><span class="badge b-teal">{{ icon('truck', 'width:12px') }} {{ $s->fulfillmentLabel() }}{{ $s->fulfillment !== 'pickup' ? ' · '.money_short($s->delivery_fee).' delivery' : '' }}</span>@foreach($s->acceptedMethods() as $m)<span class="badge b-gray plain">{{ \App\Domain\Finance::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div>
    @endif
  </div>
  <dl class="kv" style="min-width:260px;max-width:340px"><dt>Address</dt><dd>{{ $own ? $s->address : $s->publicAddress() }}</dd><dt>Contact</dt><dd>{{ $s->phone }}<br>{{ $s->email }}</dd><dt>Hours</dt><dd>{{ $s->hours }}</dd></dl>
</div></div>

<div class="mt-3"><div class="tabs" role="tablist">
  @foreach($tabList as [$k, $l, $c])
    <a class="tab {{ $tab === $k ? 'active' : '' }}" role="tab" href="{{ route('suppliers.show', [$s, 'tab' => $k]) }}">{{ $l }}@if($c !== null)<span class="count">{{ $c }}</span>@endif</a>
  @endforeach
</div></div>

@if($biz && $tab === 'packages')
  @if($evPkgs->isEmpty())
    <div class="card">@include('partials.empty', ['emoji' => '🎉', 'title' => 'No packages listed yet', 'text' => 'You can still describe your event and ask '.$s->name.' for a quotation.', 'action' => $own ? '' : '<a class="btn btn-primary" href="'.route('events.create', $s).'">Ask for a quotation</a>'])</div>
  @else
    <div class="grid grid-3">@foreach($evPkgs as $k)
      <div class="card pad-0" style="overflow:hidden;display:flex;flex-direction:column">
        @if($k->cover())<img src="{{ $k->cover() }}" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover" loading="lazy">@else<div style="aspect-ratio:16/9;display:grid;place-items:center;background:{{ $s->color }}14;font-size:2.5rem">🎉</div>@endif
        <div style="padding:14px 16px;display:flex;flex-direction:column;gap:6px;flex:1">
          <b>{{ $k->name }}</b>
          <div class="small muted">{{ $k->guestsLabel() }}{{ $k->events ? ' · '.implode(', ', $k->events) : '' }}</div>
          <p class="small mb-0">{{ \Illuminate\Support\Str::limit($k->description, 140) }}</p>
          @if($k->inclusions)<ul class="small mb-0" style="padding-left:18px">@foreach(array_slice($k->inclusions, 0, 5) as $inc)<li>{{ $inc }}</li>@endforeach @if(count($k->inclusions) > 5)<li class="muted">+{{ count($k->inclusions) - 5 }} more</li>@endif</ul>@endif
          <div class="row between mt-auto" style="padding-top:8px"><div><span class="small muted">from</span> <b style="font-size:1.1rem">{{ money($k->price) }}</b></div>@unless($own)<a class="btn btn-sm btn-primary" href="{{ route('events.create', [$s, 'package' => $k->id]) }}">Get a quote</a>@endunless</div>
        </div>
      </div>
    @endforeach</div>
    @unless($own)<p class="small muted mt-2">Want something different? <a href="{{ route('events.create', $s) }}">Ask for a custom quotation</a>.</p>@endunless
  @endif
@elseif($biz && $tab === 'policies')
  <div class="grid grid-2">
    <div class="card"><h3>How booking works</h3><ol class="small" style="padding-left:18px">
      <li>Send an inquiry with your date, venue and guest count (at least {{ $set['minNoticeDays'] }} days ahead).</li>
      <li>{{ $s->name }} replies with a quotation (valid {{ $set['quoteDays'] }} days).</li>
      <li>Accept it and pay the <b>{{ $set['reservePct'] }}% reservation fee</b> within {{ $set['reserveHours'] }} hours to hold the date.</li>
      <li>Pay the <b>{{ $set['depositPct'] }}% deposit</b> {{ $set['depositDays'] }} days before, and the <b>balance</b> {{ $set['balanceDays'] }} days before the event.</li>
    </ol><p class="small muted mb-0">Payments go to {{ $s->name }}'s own account; you send proof and they confirm each one.</p></div>
    <div class="card"><h3>On the day</h3><dl class="kv">
      @if($set['serviceAreas'])<dt>Serves</dt><dd>{{ implode(', ', $set['serviceAreas']) }}</dd>@endif
      @if($set['travelNote'])<dt>Travel</dt><dd>{{ $set['travelNote'] }}</dd>@endif
      <dt>Setup</dt><dd>{{ $set['setupHours'] }} h before, teardown {{ $set['teardownHours'] }} h after</dd></dl></div>
    <div class="card" style="grid-column:1/-1"><h3>Policies</h3>
      <p><b>Cancellation:</b> {{ $pol['cancellation'] ?? '—' }}</p><p class="mb-0"><b>Rescheduling:</b> {{ $pol['rescheduling'] ?? '—' }}</p></div>
  </div>
@elseif($tab === 'portfolio' && $biz)
  <div class="shop-portfolio">@foreach((array) $s->portfolio as $ph)<a href="{{ asset('storage/'.$ph) }}" target="_blank" rel="noopener"><img src="{{ asset('storage/'.$ph) }}" alt="Event by {{ $s->name }}" loading="lazy"></a>@endforeach</div>
@elseif($tab === 'packages')
  @if($pkgs->count())<div class="product-grid">@foreach($pkgs as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>@else @include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet', 'text' => '']) @endif
@elseif($tab === 'policies')
  <div class="grid grid-2">
    <div class="card"><h3>Payment plans</h3><dl class="kv"><dt>Accepted methods</dt><dd>Online payment through PartyRent (Xendit)</dd>
      @foreach(\App\Domain\Finance::plansOf($s) as $k)<dt>{{ \App\Domain\Finance::PLAN_LABEL[$k] }}</dt><dd>{{ \App\Domain\Finance::planSummary($s, $k) }}</dd>@endforeach
      <dt>Security deposit{{ term('security_deposit') }}</dt><dd>{{ ($pol['depositType'] ?? 'fixed') === 'percent' ? ($pol['depositValue'] ?? 0).'% of rental items' : money($pol['depositValue'] ?? 0) }} (refundable)</dd></dl>
      <p class="small muted mb-0">Paying the down payment (or the full payment) reserves your booking, and it is always credited to the total. Nothing may stay unpaid after the event date.</p></div>
    <div class="card" style="grid-column:1/-1"><h3>Location</h3>@include('partials.supplier-location', ['reveal' => $own])</div>
    <div class="card"><h3>Business policies</h3>
      <p><b>Rental hours:</b> {{ \App\Domain\Slots::fmt12($ts['open']) }} – {{ \App\Domain\Slots::fmt12($ts['close']) }} ({{ $ts['prepHours'] }}h preparation and {{ $ts['recoveryHours'] }}h recovery between bookings)</p>
      @if($s->fulfillment !== 'pickup')<p><b>Delivery fees:</b> {{ collect($s->delivery_zones ?? [])->map(fn ($z) => $z['name'].' '.money($z['fee']))->push('other areas '.money($s->delivery_fee))->implode(' · ') }} — charged per delivery location</p>@endif
      @if($s->services)<p><b>Services:</b> {{ collect($s->services)->map(fn ($x) => $x['name'].' '.money($x['amount']).(($x['per'] ?? '') === 'location' ? '/visit' : '').(! empty($x['required']) ? ' (included)' : ''))->implode(' · ') }}</p>@endif
      <p><b>Cancellation:</b> {{ $pol['cancellation'] ?? '' }}</p><p><b>Returns:</b> {{ $pol['returns'] ?? '' }}</p><p class="mb-0"><b>Rental conditions:</b> {{ $pol['conditions'] ?? '' }}</p></div>
  </div>
@elseif($tab === 'reviews')
  @include('partials.reviews-block', ['reviews' => $reviews, 'showItem' => true, 'emptyText' => 'Reviews appear here after customers complete a rental with this shop.'])
@else
  <form class="row wrap mb-2" method="GET">@csrf<input type="hidden" name="tab" value="items"><select class="input input-sm" name="category" style="width:auto" aria-label="Category" data-autosubmit><option value="">All categories</option>@foreach($items->pluck('category')->filter()->unique('id') as $c)<option value="{{ $c->id }}" @selected($catFilter === $c->id)>{{ $c->name }}</option>@endforeach</select></form>
  <div class="product-grid">@foreach($items->filter(fn ($p) => ! $catFilter || $p->category_id === $catFilter) as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
@endif
@endsection

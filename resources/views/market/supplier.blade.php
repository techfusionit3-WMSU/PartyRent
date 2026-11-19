@extends('layouts.market', ['title' => $s->name, 'nav' => 'suppliers'])

{{-- Port of C.supplier (reference js/pages/customer-discover.js) --}}
@section('content')
@php
    $pol = $s->policy ?? [];
    $ts = \App\Domain\Slots::settings($s);
    $biz = $s->isOrganization();
    $bp = (array) ($s->business_profile ?? []);
    $tabList = [['items', 'Products', $items->count()], ['packages', 'Packages', $pkgs->count()], ['policies', 'Policies & payments', null], ['reviews', 'Reviews', $reviews->count()]];
    if ($biz) { // a business leads with its event packages; single items still listed after them
        $tabList = [['packages', 'Packages', $pkgs->count()], ['items', 'Items', $items->count()], ...array_slice($tabList, 2)];
        if ($s->portfolio) { array_splice($tabList, 2, 0, [['portfolio', 'Portfolio', count($s->portfolio)]]); }
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
    <div class="row wrap gap-sm"><span class="badge b-teal">{{ icon('truck', 'width:12px') }} {{ $s->fulfillmentLabel() }}{{ $s->fulfillment !== 'pickup' ? ' · '.money_short($s->delivery_fee).' delivery' : '' }}</span>@foreach($s->acceptedMethods() as $m)<span class="badge b-gray plain">{{ \App\Domain\Finance::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div>
  </div>
  <dl class="kv" style="min-width:260px;max-width:340px"><dt>Address</dt><dd>{{ $own ? $s->address : $s->publicAddress() }}</dd><dt>Contact</dt><dd>{{ $s->phone }}<br>{{ $s->email }}</dd><dt>Hours</dt><dd>{{ $s->hours }}</dd></dl>
</div></div>

<div class="mt-3"><div class="tabs" role="tablist">
  @foreach($tabList as [$k, $l, $c])
    <a class="tab {{ $tab === $k ? 'active' : '' }}" role="tab" href="{{ route('suppliers.show', [$s, 'tab' => $k]) }}">{{ $l }}@if($c !== null)<span class="count">{{ $c }}</span>@endif</a>
  @endforeach
</div></div>

@if($tab === 'portfolio' && $biz)
  <div class="shop-portfolio">@foreach((array) $s->portfolio as $ph)<a href="{{ asset('storage/'.$ph) }}" target="_blank" rel="noopener"><img src="{{ asset('storage/'.$ph) }}" alt="Event by {{ $s->name }}" loading="lazy"></a>@endforeach</div>
@elseif($tab === 'packages')
  @if($pkgs->count())<div class="product-grid">@foreach($pkgs as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>@else @include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet', 'text' => '']) @endif
@elseif($tab === 'policies')
  <div class="grid grid-2">
    <div class="card"><h3>Payment plans</h3><dl class="kv"><dt>Accepted methods</dt><dd>Online payment through PartyRent (Xendit)</dd>
      @foreach(\App\Domain\Finance::plansOf($s) as $k)<dt>{{ \App\Domain\Finance::PLAN_LABEL[$k] }}</dt><dd>{{ \App\Domain\Finance::planSummary($s, $k) }}</dd>@endforeach
      <dt>Security deposit</dt><dd>{{ ($pol['depositType'] ?? 'fixed') === 'percent' ? ($pol['depositValue'] ?? 0).'% of rental items' : money($pol['depositValue'] ?? 0) }} (refundable)</dd></dl>
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

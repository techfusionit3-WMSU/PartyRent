@extends('layouts.market', ['title' => 'Rental List', 'nav' => 'cart'])

{{-- Port of C.cart (reference js/pages/customer-rent.js) --}}
@section('content')
@if(! $groups)
  <div class="page-head"><div><h1>Rental List</h1></div></div>
  <div class="card">@include('partials.empty', ['emoji' => '🛒', 'title' => 'Your rental list is empty', 'text' => 'Add items or packages, then set the dates, times and quantities for each day.', 'action' => '<div class="row wrap" style="justify-content:center"><a class="btn btn-primary" href="'.route('home').'">Browse supplies</a><a class="btn" href="'.route('ai').'">'.icon('sparkle').'Plan with AI</a></div>'])</div>
@else
<div class="page-head"><div><h1>Rental List</h1><p>Items from different shops stay grouped by shop. Tick what you want to check out now — unticked items stay in your list.</p></div>
  <div class="row">@if(count($groups) > 1)<button class="btn btn-sm" data-modal="cmpModal">{{ icon('layers') }}Compare suppliers</button>@endif<a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ icon('plus') }}Add more items</a></div>
</div>
@include('partials.period-banner')
@php($allIds = collect($groups)->flatMap(fn ($g) => array_column($g['lines'], 'id'))->all())
<div class="card row wrap between mb-2" style="padding:12px 16px">
  <label class="check mb-0"><input type="checkbox" class="sel-box" data-select='@json($allIds)' @checked($selected === $total) @if($selected && $selected < $total) data-indet="1" @endif> <b>Select all</b> <span class="small muted">({{ $total }} item{{ $total > 1 ? 's' : '' }} from {{ count($groups) }} shop{{ count($groups) > 1 ? 's' : '' }})</span></label>
  <span class="small muted">{{ $selected }} selected</span>
</div>

<div class="grid grid-side">
  <div data-reload-on-save>
    @foreach($groups as $g)
      @php($s = $g['supplier'])
      @php($ids = array_column($g['lines'], 'id'))
      @php($allOn = count($g['chosen']) === count($g['lines']))
      <div class="card pad-0 cart-group" data-group="{{ $s->id }}">
        <div class="cg-head">
          <div class="row" style="min-width:0"><input type="checkbox" class="sel-box" data-select='@json($ids)' @checked($allOn) @if($g['chosen'] && ! $allOn) data-indet="1" @endif aria-label="Select all items from {{ $s->name }}"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:{{ $s->color }}1a">{{ $s->logo }}</div><div style="min-width:0"><a class="strong" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a><div class="small muted">{{ $s->city }} · {{ $s->fulfillmentLabel() }} · {{ collect(\App\Domain\Finance::plansOf($s))->map(fn ($k) => str_replace(' Payment Plan', '', \App\Domain\Finance::PLAN_LABEL[$k]))->implode(' / ') }}</div></div></div>
          <div class="row"><div class="right"><div class="small muted">{{ count($g['chosen']) }} of {{ count($g['lines']) }} selected</div><b class="price">{{ $g['quote'] ? money($g['quote']['total']) : '—' }}</b></div>
            <a class="btn btn-sm {{ $g['chosen'] && ! $g['chosenBad'] ? '' : 'disabled' }}" href="{{ route('checkout', ['supplier' => $s->id]) }}" @if(! $g['chosen'] || $g['chosenBad']) aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5" @endif>Check out this shop</a>
          </div>
        </div>
        @if(! $g['check']['ok'])<div class="alert alert-danger" style="margin:12px 18px 0">{{ icon('alert') }}<div>Some dates exceed the stock available for the selected time. Lower the highlighted quantities, change the time or remove those dates.</div></div>@endif
        @if($g['duration'])<div class="alert alert-danger" style="margin:12px 18px 0">{{ icon('alert') }}<div>{!! collect($g['duration'])->pluck('message')->map(fn ($m) => e($m))->implode('<br>') !!}</div></div>@endif
        @if($g['timeIssues'])<div class="alert alert-danger" style="margin:12px 18px 0">{{ icon('alert') }}<div>{!! collect($g['timeIssues'])->map(fn ($m) => e($m))->implode('<br>') !!}</div></div>@endif
        @foreach($g['lines'] as $l)
          @php($item = $l['type'] === 'package' ? inv()->package($l['ref_id']) : inv()->product($l['ref_id']))
          @continue(! $item)
          @php($ds = array_keys($l['days']))
          @php($t = \App\Domain\Slots::time($l) ?? \App\Domain\Slots::defaultTime($s, $item))
          @php($siblings = collect($g['lines'])->reject(fn ($x) => $x['id'] === $l['id'])->values()->all())
          <div class="cart-line" data-line="{{ $l['id'] }}">
            <div class="cl-top"><input type="checkbox" class="sel-box" data-select='@json([$l['id']])' @checked($l['selected']) aria-label="Select {{ $item->name }}">@include('partials.thumb', ['item' => $item])<div class="grow" style="min-width:0"><div class="row between top"><div style="min-width:0">
              <a class="strong" href="{{ $l['type'] === 'package' ? route('packages.show', $item) : route('products.show', $item) }}">{{ $item->name }}</a>@if($l['type'] === 'package') <span class="badge b-pink plain">Package</span>@endif
              <div class="small muted">{{ $ds ? count($ds).' date'.(count($ds) > 1 ? 's' : '').' · '.fmt_range($ds[0], end($ds)) : 'No dates' }}{{ $l['type'] === 'item' && count($item->tiers ?? []) > 1 ? ' · tiered pricing applies per day' : '' }}</div>
              @if(rental_period_outside($l['days']))<div class="small" style="color:#B45309">{{ icon('info', 'width:13px;vertical-align:-2px') }} These dates differ from your selected rental period.</div>@endif
              @if($l['type'] === 'package')@foreach(\App\Domain\Packages::describe(\App\Domain\Packages::def($item), $l['sel'] ?? null) as $d)<div class="small muted">{{ $d }}</div>@endforeach @endif
              </div>
              <form method="POST" action="{{ route('cart.remove', $l['id']) }}" data-confirm="Remove this item and its schedule from your rental list?" data-confirm-title="Remove item?" data-confirm-ok="Remove" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">{{ icon('trash') }}Remove</button></form>
            </div>
            <form class="row wrap mt-1" style="gap:8px;align-items:flex-end" method="POST" action="{{ route('cart.time', $l['id']) }}">@csrf
              <div class="time-pair" style="max-width:340px;flex:1 1 260px"><label class="small">Start time @include('partials.time-select', ['name' => 'start', 'value' => $t['start'], 's' => $s, 'attrs' => 'data-autosubmit aria-label="Start time"'])</label><label class="small">End time @include('partials.time-select', ['name' => 'end', 'value' => $t['end'], 's' => $s, 'attrs' => 'data-autosubmit aria-label="End time"'])</label></div>
              <div class="small"><span class="badge b-purple plain">{{ icon('clock', 'width:13px;height:13px') }} {{ \App\Domain\Slots::time($l) ? \App\Domain\Slots::duration($l)['label'] : 'All day' }}</span>@if($hl = \App\Domain\Slots::hourLimitLabel($item))<div class="muted">{{ $hl }}</div>@endif</div>
            </form>
            </div></div>
            <div class="mt-1" data-scheduler data-type="{{ $l['type'] }}" data-ref="{{ $l['ref_id'] }}" data-days='@json((object) $l['days'])' @if($l['type'] === 'package') data-sel='@json(\App\Domain\Packages::cleanSel(\App\Domain\Packages::def($item), $l['sel'] ?? null))' @endif data-siblings='@json($siblings)' data-save="{{ route('cart.update', $l['id']) }}"></div>
          </div>
        @endforeach
        @if($g['quote'])<div style="padding:0 18px 14px">@include('partials.rental-charges', ['q' => $g['quote'], 'estimate' => true, 'note' => $s->fulfillment !== 'pickup' ? 'Delivery is shown at the standard rate. Choose your delivery zone (and add more delivery locations) at checkout.' : 'Pickup at the shop — no delivery fee.'])</div>@endif
      </div>
    @endforeach
  </div>
  <aside class="card sticky-side">
    <h3>Selected for checkout</h3>
    @php($selGroups = array_filter($groups, fn ($g) => $g['quote']))
    @forelse($selGroups as $g)
      <div class="sum-line"><span>{{ $g['supplier']->name }}<div class="small muted">{{ count($g['chosen']) }} item(s) · own order & payment plan</div></span><span>{{ money($g['quote']['total']) }}</span></div>
    @empty
      <p class="muted small">No items selected yet.</p>
    @endforelse
    @if($selGroups)<div class="sum-line total"><span>Combined total ({{ count($selGroups) }} shop{{ count($selGroups) > 1 ? 's' : '' }})</span><span class="price">{{ money($grand) }}</span></div>@endif
    <a class="btn btn-primary btn-block btn-lg mt-1 {{ $selected && ! $blocked ? '' : 'disabled' }}" href="{{ route('checkout') }}" @if(! $selected || $blocked) aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5" @endif>Proceed to checkout{{ $selected ? ' ('.$selected.')' : '' }}</a>
    @if($blocked)<p class="small mt-1 mb-0" style="color:var(--red)">Fix the highlighted issues in the selected items first.</p>@endif
    <p class="small muted mt-1">Each shop receives its own rental request with its own payment plan, schedule and total. Payment percentages are always based on that shop's order total, not the combined total.</p>
    <div class="alert alert-info">{{ icon('info') }}<div class="small">Items in your list are <b>not reserved</b>. Stock is only reserved when a supplier accepts your request.</div></div>
  </aside>
</div>

@if(count($groups) > 1)
<div class="modal-backdrop hidden" id="cmpModal"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Compare suppliers in your list</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body">
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Supplier</th><th class="num">Rental charges</th><th>Delivery</th><th>Payment plans</th><th class="num">Deposit</th><th>Availability</th></tr></thead><tbody>
  @foreach($groups as $g)
    @php($s = $g['supplier'])
    @php($q = \App\Http\Controllers\Customer\CartController::estimate($s, $g['lines']))
    <tr><td data-label="Supplier"><b>{{ $s->name }}</b><div class="small muted">{{ $s->city }} · ★ {{ rating_text($s) }}</div></td><td data-label="Rental charges" class="num">{{ money($q['total']) }}</td>
      <td data-label="Delivery">{{ $s->fulfillmentLabel() }}@if($s->fulfillment !== 'pickup')<div class="small muted">from {{ money(min(array_merge([(float) $s->delivery_fee], array_column($s->delivery_zones ?? [], 'fee')))) }}</div>@endif</td>
      <td data-label="Payment plans">{!! collect(\App\Domain\Finance::plansOf($s))->map(fn ($k) => e(\App\Domain\Finance::PLAN_LABEL[$k]))->implode('<br>') !!}</td><td data-label="Deposit" class="num">{{ money($q['deposit']) }}</td>
      <td data-label="Availability">@if($g['check']['ok'])<span class="badge b-green">All dates OK</span>@else<span class="badge b-red">Conflicts</span>@endif</td></tr>
  @endforeach
  </tbody></table></div>
</div></div></div>
@endif
@endif
@endsection

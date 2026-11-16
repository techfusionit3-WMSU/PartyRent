{{-- Package contents + customer choices. $def (Packages::def), $pp (Packages::price), $interactive (bool) --}}
@php($sel = $pp['sel'])
{{-- A plain package (only included items, no options or guests) uses the reference layout. --}}
@php($simple = ! array_filter($pp['rows'], fn ($r) => $r['group'] !== 'included') && ! collect($def['components'])->contains(fn ($c) => in_array($c['kind'], ['choice', 'addon'], true)) && empty($def['minGuests']) && empty($def['maxGuests']))
@php($kindBadge = ['sale' => '<span class="badge b-purple plain">Consumable</span>', 'service' => '<span class="badge b-gold plain">Service</span>', 'rent' => ''])
<div class="pkg-contents" data-pkg-contents>
  <div class="pkg-sec">
    @unless($simple)<div class="strong">Included</div>@endunless
    @foreach(array_filter($pp['rows'], fn ($r) => $r['group'] === 'included') as $r)
      <div class="row pkg-row">
        @if(isset($r['productId']) && ($ip = inv()->product($r['productId'])))@include('partials.thumb', ['item' => $ip, 'cls' => 'sm'])@endif
        <div class="grow">@if($simple && isset($r['productId']))<a href="{{ route('products.show', $r['productId']) }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif {!! $kindBadge[$r['kind']] ?? '' !!}@if(! empty($r['perGuest']))<div class="small muted">{{ rtrim(rtrim(number_format($r['perGuest'], 2), '0'), '.') }} per guest</div>@endif</div>
        <b>× {{ $r['qty'] }}</b>
      </div>
    @endforeach
  </div>

  @foreach($def['components'] as $i => $c)
    @continue($c['kind'] !== 'choice' || ! $c['options'])
    <div class="pkg-sec">
      <div class="strong">{{ $c['label'] }} <span class="small muted">· pick one</span></div>
      @foreach($c['options'] as $j => $o)
        @php($op = inv()->product($o['productId']))
        @continue(! $op)
        <label class="row pkg-row check">
          <input type="radio" name="choice[{{ $i }}]" value="{{ $j }}" @checked(($sel['choices'][$i] ?? 0) === $j) @disabled(! $interactive) data-sel-choice="{{ $i }}">
          @include('partials.thumb', ['item' => $op, 'cls' => 'sm'])
          <span class="grow">{{ $op->name }} <span class="small muted">× {{ $o['perGuest'] ? rtrim(rtrim(number_format($o['perGuest'], 2), '0'), '.').' per guest' : $o['qty'] }}</span></span>
          <span class="small">{{ $o['priceDelta'] > 0 ? '+'.money($o['priceDelta']).' each' : ($o['priceDelta'] < 0 ? '−'.money(abs($o['priceDelta'])).' each' : 'Included') }}</span>
        </label>
      @endforeach
    </div>
  @endforeach

  @php($addons = array_filter($def['components'], fn ($c) => $c['kind'] === 'addon'))
  @if($addons)
    <div class="pkg-sec">
      <div class="strong">Add-ons <span class="small muted">· optional</span></div>
      @foreach($addons as $i => $c)
        @php($ap = $c['productId'] ? inv()->product($c['productId']) : null)
        @continue($c['productId'] && ! $ap)
        @php($price = $c['price'] ?? ($ap ? \App\Domain\Pricing::unitPrice($ap, $c['qty']) : 0))
        <label class="row pkg-row check">
          <input type="checkbox" value="{{ $i }}" @checked(in_array($i, $sel['addons'] ?? [], true)) @disabled(! $interactive) data-sel-addon>
          @if($ap)@include('partials.thumb', ['item' => $ap, 'cls' => 'sm'])@endif
          <span class="grow">{{ $ap?->name ?? $c['name'] }}@if($c['qty'] > 1) <span class="small muted">× {{ $c['qty'] }}</span>@endif</span>
          <span class="small">+{{ money($price * $c['qty']) }}{{ $ap && ! $ap->isSale() ? ' / day' : '' }}</span>
        </label>
      @endforeach
    </div>
  @endif

  @if(\App\Domain\Packages::usesGuests($def))
    <div class="pkg-sec">
      <label class="strong" for="pkgGuests">Number of guests</label>
      <div class="row" style="gap:8px"><input class="input input-sm" style="width:110px" type="number" id="pkgGuests" min="{{ $def['minGuests'] ?? 1 }}" @if($def['maxGuests']) max="{{ $def['maxGuests'] }}" @endif value="{{ $sel['guests'] }}" @disabled(! $interactive) data-sel-guests>
        <span class="small muted">{{ $def['minGuests'] ? 'From '.$def['minGuests'] : '' }}{{ $def['maxGuests'] ? ' up to '.$def['maxGuests'] : '' }} guests. Per-guest items scale with this.</span></div>
    </div>
  @endif

  <div class="pkg-sec pkg-total" @if($simple) hidden @endif>
    <div class="row between"><span>Package price</span><b class="price" data-pkg-price>{{ money($pp['package']) }}</b></div>
    @if($pp['perDay'] > 0 && $pp['once'] > 0)<div class="small muted" data-pkg-split>{{ money($pp['perDay']) }} per day for rentals + {{ money($pp['once']) }} once for consumables & services</div>
    @elseif($pp['once'] > 0)<div class="small muted" data-pkg-split>Charged once (consumables & services)</div>
    @else<div class="small muted" data-pkg-split>Per package, per day</div>@endif
    @if($pp['savings'] > 0)<div class="small" style="color:var(--green)" data-pkg-savings>Save {{ money($pp['savings']) }} vs. {{ money($pp['separate']) }} separately</div>@endif
  </div>
</div>

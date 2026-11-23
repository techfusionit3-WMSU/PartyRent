@extends('layouts.dash', ['title' => 'Shop Profile & Settings', 'nav' => 'shop'])

{{-- Reference SP.shop: seven tabs, each form saves its own section (+ app: PH address picker, location type, map pin) --}}
@php
    $owner = auth()->user()->orgCan('shop.manage');
    $p = (array) ($s->policy ?? []);
    $cfg = \App\Domain\Finance::planConfig($s);
    $ts = \App\Domain\Slots::settings($s);
    $o = fn ($k, $def = null) => old($k, data_get($d, $k, $def));
    $allTimes = array_map(fn ($i) => \App\Domain\Slots::fromMin($i * 30), range(0, 48));
    $ex = function ($k) use ($cfg) {
        $c = $cfg[$k];
        if ($k === 'full') {
            return 'On ₱10,000: one payment of ₱10,000.00.';
        }
        $dn = 10000 * $c['downPct'] / 100;

        return 'On ₱10,000: down payment '.money($dn).' (reserves the booking) + '.($k === 'installment' ? 'installments' : 'final balance').' '.money(10000 - $dn).' = ₱10,000.00 (never added on top).';
    };
    $pf = fn ($k, $n, $label, $v, $attrs = 'min="0"') => '<div class="field"><label>'.e($label).'</label><input class="input" type="number" name="plans['.$k.']['.$n.']" value="'.e($v).'" '.$attrs.'></div>';
    $dmg = \App\Domain\Damage::policy($s);
    $pctIn = fn ($name, $v) => '<div class="row" style="gap:6px"><input class="input" type="number" min="0" max="100" step="0.5" name="'.$name.'" value="'.e(+$v).'"><span class="small muted">%</span></div>';
    $dmgBlock = function ($pre, $x) use ($pctIn) {
        $lost = '';
        foreach (['brand_new' => 'Brand New', 'good_as_new' => 'Good as New', 'used' => 'Used'] as $k => $l) {
            $lost .= '<label class="small">'.$l.'</label>'.$pctIn($pre.'[lost_'.$k.']', $x['lost'][$k] ?? 0);
        }

        return '<div class="grid grid-3"><div class="card card-flat" style="padding:12px"><label class="check"><input type="checkbox" name="'.$pre.'[rep_on]" value="1"'.(! empty($x['repairable']['enabled']) ? ' checked' : '').'> <b>Repairable Damage</b></label><div class="small muted mb-1">Can be repaired and reused</div>'.$pctIn($pre.'[rep_pct]', $x['repairable']['pct'] ?? 0).'</div>'
            .'<div class="card card-flat" style="padding:12px"><label class="check"><input type="checkbox" name="'.$pre.'[non_on]" value="1"'.(! empty($x['nonRepairable']['enabled']) ? ' checked' : '').'> <b>Non-Repairable Damage</b></label><div class="small muted mb-1">Cannot be repaired or safely used</div>'.$pctIn($pre.'[non_pct]', $x['nonRepairable']['pct'] ?? 0).'</div>'
            .'<div class="card card-flat" style="padding:12px"><label class="check"><input type="checkbox" name="'.$pre.'[lost_on]" value="1"'.(! empty($x['lost']['enabled']) ? ' checked' : '').'> <b>Lost or Not Returned</b></label><div class="small muted mb-1">By condition before rental</div>'.$lost.'</div></div>';
    };
@endphp
@section('content')
<div class="page-head"><div><h1>{{ $s->isOrganization() ? 'Business Profile' : 'Shop Profile & Settings' }}</h1><p>{{ $s->name }} · <a href="{{ route('suppliers.show', $s) }}">View public shop</a></p></div></div>
@unless($owner)<div class="alert alert-info mb-2">{{ icon('info') }}<div>Only the Organization Admin can change shop settings. You can view them here.</div></div>@endunless
<div class="tabs">@foreach($tabs as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('supplier.shop', ['tab' => $k]) }}">{{ $l }}</a>@endforeach</div>
<form class="card" method="POST" action="{{ route('supplier.shop.update') }}" style="max-width:900px" id="sf" enctype="multipart/form-data">
  @csrf<input type="hidden" name="section" value="{{ $tab }}">
  <fieldset @disabled(! $owner) style="border:0;padding:0;margin:0;min-width:0">
  @switch($tab)
    @case('profile')
      <div class="form-grid">
        <div class="field"><label>Business name</label><input class="input" name="name" required value="{{ $o('name') }}"></div>
        <div class="field"><label>Logo (emoji)</label><input class="input" name="logo" maxlength="4" value="{{ $o('logo') }}" style="width:90px"></div>
        <div class="field full"><label>{{ $s->isOrganization() ? 'About us' : 'Business description' }}</label><textarea class="input" name="description" required>{{ $o('description') }}</textarea></div>
        <div class="field"><label>Contact number</label><input class="input" name="phone" data-phone required value="{{ $o('phone') }}"></div>
        <div class="field"><label>Email address</label><input class="input" type="email" name="email" required value="{{ $o('email') }}"></div>
        <div class="field"><label>Operating hours</label><input class="input" name="hours" required value="{{ $o('hours') }}"></div>
        <div class="field"><label>Brand color</label><input class="input" type="color" name="color" value="{{ $o('color', '#E11D74') }}" style="height:44px;padding:4px"></div>
        <div class="field"><label>Supplier type</label><div><span class="badge b-purple">{{ \App\Domain\Applications::SUPPLIER_TYPES[$s->type] ?? 'Individual Supplier' }}</span></div></div>
      </div>
      @if($s->isOrganization())
        @php($bp = (array) ($s->business_profile ?? []))
        <h3 class="mt-2 mb-1">What your business offers</h3>
        <div class="field @error('events_catered') invalid @enderror"><label>Events you cater</label>@include('partials.tag-input', ['name' => 'events_catered', 'values' => old('events_catered', $bp['events'] ?? []), 'suggest' => array_keys(\App\Domain\Ai::EVENTS), 'placeholder' => 'Type an event, e.g. Graduation'])@error('events_catered')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field @error('offers') invalid @enderror"><label>Services you offer</label>@include('partials.tag-input', ['name' => 'offers', 'values' => old('offers', $bp['offers'] ?? []), 'suggest' => \App\Models\Supplier::OFFER_SUGGESTIONS, 'placeholder' => 'Type a service, e.g. Balloon styling'])@error('offers')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="form-grid">
          <div class="field"><label>Event size you can handle</label><select class="input" name="event_size" required>@foreach(\App\Models\Supplier::EVENT_SIZES as $k => $l)<option value="{{ $k }}" @selected(old('event_size', $bp['eventSize'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
          <div class="field"><label>Packages start at (₱) <span class="muted small">(optional)</span></label><input class="input" type="number" min="0" step="1" name="packages_from" value="{{ old('packages_from', $bp['packagesFrom'] ?? '') }}"></div>
        </div>
        @include('partials.business-photos', ['photos' => ['logo' => $s->logo_path, 'cover' => $s->cover_path, 'portfolio' => (array) ($s->portfolio ?? [])]])
      @endif
      @include('partials.location-fields', ['locFor' => $s->isOrganization() ? 'business' : 'individual'])
      @break
    @case('delivery')
      <div class="stack-sm">@foreach(['delivery' => 'Delivery only', 'pickup' => 'Pickup only', 'both' => 'Both delivery and pickup'] as $v => $l)<label class="check"><input type="radio" name="fulfillment" value="{{ $v }}" @checked($o('fulfillment') === $v)> {{ $l }}</label>@endforeach</div>
      <div class="label mt-2 mb-1">Delivery zones & rates</div>
      <p class="small muted">Delivery fees are charged <b>per delivery location</b>. A customer delivering to a church and a reception pays the fee for each.</p>
      <div id="zones">
        @foreach(array_merge(old('zones', $s->delivery_zones ?? []), [['name' => '', 'fee' => '']]) as $i => $z)
          <div class="row mb-1" style="gap:8px" data-row><input class="input input-sm grow" name="zones[{{ $i }}][name]" value="{{ $z['name'] }}" placeholder="Zone name, e.g. Quezon City" aria-label="Zone name"><input class="input input-sm" type="number" min="0" name="zones[{{ $i }}][fee]" value="{{ $z['fee'] }}" style="width:110px" aria-label="Fee"><button type="button" class="btn btn-xs btn-ghost" data-rmrow aria-label="Remove zone">{{ icon('trash') }}</button></div>
        @endforeach
      </div>
      <button type="button" class="btn btn-sm" data-addrow="#zones">{{ icon('plus') }}Add zone</button>
      <div class="field mt-2"><label>Standard rate for other areas (₱ per location)</label><input class="input" type="number" min="0" name="delivery_fee" value="{{ $o('delivery_fee', 0) }}" style="max-width:200px"></div>
      @break
    @case('hours')
      <p class="small muted">Customers pick their rental start and end time within these hours (12-hour format). Preparation (delivery & setup) and recovery (dismantling & pickup) time is <b>added around every booking</b> when checking stock, so the same units can be rented again later the same day only when the times don't overlap.</p>
      <div class="form-grid">
        @foreach(['open' => 'Rental hours start', 'close' => 'Rental hours end'] as $k => $l)<div class="field"><label>{{ $l }}</label><select class="input" name="{{ $k }}">@foreach($allTimes as $t)<option value="{{ $t }}" @selected($t === old($k, $ts[$k]))>{{ \App\Domain\Slots::fmt12($t) }}</option>@endforeach</select></div>@endforeach
        <div class="field"><label>Preparation time before each booking (hours)</label><input class="input" type="number" min="0" max="24" step="0.5" name="prepHours" value="{{ old('prepHours', +$ts['prepHours']) }}"></div>
        <div class="field"><label>Recovery time after each booking (hours)</label><input class="input" type="number" min="0" max="24" step="0.5" name="recoveryHours" value="{{ old('recoveryHours', +$ts['recoveryHours']) }}"></div>
      </div>
      <div class="alert alert-info">{{ icon('info') }}<div class="small">Example with your settings: a 6:00 AM – 11:00 AM booking blocks the units from {{ \App\Domain\Slots::fmt12(max(0, 360 - (int) round($ts['prepHours'] * 60))) }} to {{ \App\Domain\Slots::fmt12(660 + (int) round($ts['recoveryHours'] * 60)) }}. Minimum / maximum hours per booking are set on each product or package.</div></div>
      @break
    @case('plans')
      @if(\App\Domain\OnlinePay::enabled())
        <div class="field"><label>How customers pay</label><div class="small">Customers pay online through PartyRent (GCash, Maya or card); each payment is confirmed automatically.</div>
          <label class="check mt-1"><input type="checkbox" name="methods[]" value="cash" @checked(in_array('cash', old('methods', $s->methods ?? []), true))> Also accept <b>cash</b> handed to us at delivery or pickup (you confirm it in the app)</label></div>
      @else
        <div class="field"><label>Accepted payment methods</label>@foreach(['cash' => 'Cash', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit'] as $v => $l)<label class="check"><input type="checkbox" name="methods[]" value="{{ $v }}" @checked(in_array($v, old('methods', $s->methods ?? []), true))> {{ $l }}</label> @endforeach</div>
      @endif
      <p class="small muted">The first payment (down payment or full payment) is due within <b>{{ \App\Domain\Rentals::PAY_HOURS }} hours</b> of accepting a request: it reserves the items, and an unpaid request is cancelled after that so the stock is free again. Later payments are due by the event date. The down payment is always credited to the order total.</p>
      {{-- the "due within N days" settings no longer apply (first payment: 24 h); kept as hidden values so saving still validates --}}
      @foreach(['standard' => ['downDays'], 'full' => ['days'], 'installment' => ['downDays']] as $k => $hid)@foreach($hid as $n)<input type="hidden" name="plans[{{ $k }}][{{ $n }}]" value="{{ $cfg[$k][$n] }}">@endforeach @endforeach
      @foreach(['standard' => [['downPct', 'Down payment (%)', 'min="0.5" max="100" step="0.5"']],
                'full' => [],
                'installment' => [['downPct', 'Down payment (%)', 'min="0.5" max="99.5" step="0.5"'], ['minMonths', 'Minimum months before the event', 'min="1" max="24"'], ['maxInstallments', 'Maximum number of installments', 'min="1" max="24"']]] as $k => $fields)
        <div class="card card-flat mb-2" style="padding:14px"><label class="check"><input type="checkbox" name="plans[{{ $k }}][enabled]" value="1" @checked($cfg[$k]['enabled'])> <b>{{ \App\Domain\Finance::PLAN_LABEL[$k] }}</b></label>
          <div class="form-grid mt-1">@foreach($fields as [$n, $label, $attrs]){!! $pf($k, $n, $label, $cfg[$k][$n], $attrs) !!}@endforeach</div><div class="small muted">{{ $ex($k) }}</div></div>
      @endforeach
      <div class="field"><label>Security deposit (separate, refundable)</label><div class="row" style="gap:6px"><select class="input" name="depositType" style="width:160px"><option value="fixed" @selected(($p['depositType'] ?? 'fixed') === 'fixed')>₱ fixed</option><option value="percent" @selected(($p['depositType'] ?? '') === 'percent')>% of rental fee</option></select><input class="input" type="number" min="0" name="depositValue" value="{{ $p['depositValue'] ?? 0 }}"></div></div>
      <p class="small muted mb-0">Changes apply to new requests; accepted orders keep the terms they were quoted.</p>
      @break
    @case('services')
      <p class="small muted">Services appear as separate Service Fee lines in the customer's Rental Charges. <b>Included</b> services are always added; optional ones are chosen by the customer at checkout. "Per location" multiplies the fee by the number of delivery locations.</p>
      <div id="svcs">
        @foreach(array_merge($s->services ?? [], [['id' => '', 'name' => '', 'description' => '', 'amount' => '', 'per' => 'order', 'required' => false]]) as $i => $x)
          <div class="card card-flat mb-1" style="padding:12px" data-row><input type="hidden" name="services[{{ $i }}][id]" value="{{ $x['id'] }}">
            <div class="form-grid">
              <div class="field"><label class="small">Service</label><input class="input input-sm" name="services[{{ $i }}][name]" value="{{ $x['name'] }}" placeholder="e.g. Setup and installation"></div>
              <div class="field"><label class="small">Amount (₱)</label><input class="input input-sm" type="number" min="0" name="services[{{ $i }}][amount]" value="{{ $x['amount'] }}"></div>
              <div class="field full"><label class="small">What is included</label><input class="input input-sm" name="services[{{ $i }}][description]" value="{{ $x['description'] }}"></div>
              <div class="field"><label class="small">Charged</label><select class="input input-sm" name="services[{{ $i }}][per]"><option value="order" @selected(($x['per'] ?? '') !== 'location')>Once per order</option><option value="location" @selected(($x['per'] ?? '') === 'location')>Per delivery location</option></select></div>
              <div class="field"><label class="check mt-2"><input type="checkbox" name="services[{{ $i }}][required]" value="1" @checked(! empty($x['required']))> Always included</label></div>
            </div>
            <button type="button" class="btn btn-xs btn-ghost" data-rmrow>{{ icon('trash') }}Remove</button>
          </div>
        @endforeach
      </div>
      <button type="button" class="btn btn-sm" data-addrow="#svcs">{{ icon('plus') }}Add service</button>
      @break
    @case('damage')
      <p class="small muted">Compensation = product replacement value × compensation % × affected quantity. Customers are only charged after you review and confirm an inspection.</p>
      <div class="label mb-1">Shop default</div>
      {!! $dmgBlock('d', $dmg) !!}
      <div class="label mt-3 mb-1">Rules for specific product categories (optional)</div>
      @foreach($dmg['categories'] as $cid => $cx)
        <div class="card card-flat mb-2" style="padding:12px"><div class="row between mb-1"><b>{{ $cats->firstWhere('id', $cid)?->name ?? $cid }}</b><button class="btn btn-xs btn-ghost" name="removeCategory" value="{{ $cid }}">{{ icon('trash') }}Remove rule</button></div>
          {!! $dmgBlock('c['.$cid.']', ['repairable' => array_merge($dmg['repairable'], (array) ($cx['repairable'] ?? [])), 'nonRepairable' => array_merge($dmg['nonRepairable'], (array) ($cx['nonRepairable'] ?? [])), 'lost' => array_merge($dmg['lost'], (array) ($cx['lost'] ?? []))]) !!}</div>
      @endforeach
      <div class="row" style="gap:8px"><select class="input input-sm" name="addCategory" style="width:auto"><option value="">Add a rule for category…</option>@foreach($cats->reject(fn ($c) => isset($dmg['categories'][$c->id])) as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select><button class="btn btn-sm">{{ icon('plus') }}Add rule</button></div>
      @break
    @case('policies')
      <div class="field"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="{{ old('cancelDays', $p['cancelDays'] ?? 0) }}" style="max-width:200px"></div>
      <div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>{{ old('cancellation', $p['cancellation'] ?? '') }}</textarea></div>
      <div class="field"><label>Return policy</label><textarea class="input" name="returns" required>{{ old('returns', $p['returns'] ?? '') }}</textarea></div>
      <div class="field"><label>Rental conditions / business policies</label><textarea class="input" name="conditions" required>{{ old('conditions', $p['conditions'] ?? '') }}</textarea></div>
      @break
  @endswitch
  @if($owner)<div class="row end mt-2"><button class="btn btn-primary">Save changes</button></div>@endif
  </fieldset>
</form>
@push('scripts')
<script>
// Editable lists (zones, services): add a blank row (renumbered) or remove one.
(function () {
  var f = document.getElementById('sf'); if (!f) return;
  f.addEventListener('click', function (e) {
    var rm = e.target.closest('[data-rmrow]'); if (rm) { e.preventDefault(); rm.closest('[data-row]').remove(); return; }
    var add = e.target.closest('[data-addrow]'); if (!add) return; e.preventDefault();
    var box = f.querySelector(add.dataset.addrow), rows = box.querySelectorAll('[data-row]'), last = rows[rows.length - 1];
    var n = 0; box.querySelectorAll('[name]').forEach(function (i) { var m = /\[(\d+)\]/.exec(i.name); if (m) n = Math.max(n, +m[1] + 1); });
    var c = last.cloneNode(true);
    c.querySelectorAll('[name]').forEach(function (i) { i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); if (i.type === 'checkbox') i.checked = false; else if (i.tagName === 'SELECT') i.selectedIndex = 0; else i.value = ''; });
    box.appendChild(c); var first = c.querySelector('input:not([type=hidden])'); if (first) first.focus();
  });
})();
</script>
@endpush
@endsection

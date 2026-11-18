{{--
  Rent box body shared by the product and package pages (inside the rent form, inside [data-rentbox]).
  One rental date by default; "Multiple days" shows the end date. The quantity applies to every day; the
  "Different quantity per day" switch only shows for several days and lists exactly those days (no manual dates),
  and while it is on the single quantity is hidden.
  Vars: $type item|package, $ref, $days, $siblings, $start, $end, $qty, $minDate, $sup, $item, $sel (package selection|null),
        $stockText (optional), $timed (bool, show the time selects).
--}}
@php($multi = $start && $end && $end > $start)
@php($custom = $multi && count(array_unique(array_map('intval', array_values((array) $days)))) > 1)
<div class="rb-dates {{ $multi ? 'md-on' : '' }}" data-multiday>
  <div class="row between wrap mb-1" style="gap:8px"><span class="label mb-0">Rental date</span><label class="switch"><input type="checkbox" id="mdToggle" data-md-toggle @checked($multi)><span class="sw"></span>Multiple days</label></div>
  <div class="form-grid rb-dgrid">
    <div class="field"><label for="ps"><span class="md-off-txt">Date</span><span class="md-on-txt">Start date</span></label><input class="input" type="date" id="ps" min="{{ $minDate }}" value="{{ $start }}" data-md-start></div>
    <div class="field {{ $multi ? '' : 'hidden' }}" data-md-end><label for="pe">End date</label><input class="input" type="date" id="pe" min="{{ $minDate }}" value="{{ $end }}"></div>
  </div>
  @if($timed ?? true)
    <div class="form-grid">@include('partials.rent-times', ['sup' => $sup, 'item' => $item, 'part' => 'selects'])</div>
    @include('partials.rent-times', ['sup' => $sup, 'item' => $item, 'part' => 'line'])
  @endif
</div>
@if($type === 'item' && ! $item->isSale() && $item->max_rental_days)<div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">The supplier allows a maximum of <b>{{ $item->max_rental_days }} day{{ $item->max_rental_days > 1 ? 's' : '' }}</b> per booking for this item.</div></div>@endif

<div class="row wrap between" style="gap:10px;align-items:flex-end">
  <div id="qtyRow" class="{{ $custom ? 'hidden' : '' }}"><div class="label mb-1">{{ $type === 'package' ? 'Packages' : 'Quantity' }}<span class="md-on-txt"> per day</span></div><div class="qty" id="qbox"><button type="button" data-d aria-label="Decrease">−</button><input type="number" id="pq" min="1" value="{{ max(1, (int) $qty) }}" aria-label="Quantity"><button type="button" data-i aria-label="Increase">+</button></div></div>
  <div class="right">@if(! empty($stockText))<div class="small muted">{{ $stockText }}</div>@endif<div id="availTxt"></div></div>
</div>
<div id="perDayWrap" class="mt-2 {{ $multi ? '' : 'hidden' }}">
  <label class="switch"><input type="checkbox" id="perDayToggle" @checked($custom)><span class="sw"></span>Different quantity per day</label>
  <div id="perDay" class="mt-1 {{ $custom ? '' : 'hidden' }}">
    <p class="small muted mb-1">Set how many you need on each of your rental days.</p>
    <div data-scheduler data-fixed data-type="{{ $type }}" data-ref="{{ $ref }}" @if($sel !== null) data-sel='@json($sel)' @endif data-days='@json((object) $days)' data-siblings='@json($siblings)' data-sync="#daysInput"></div>
  </div>
</div>

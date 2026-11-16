{{-- Filters side panel body (reference filtersHtml). Submitted by the drawer's "Show results" button. --}}
@php($opt = fn ($v, $l, $cur) => '<option value="'.e($v).'"'.((string) $v === (string) $cur ? ' selected' : '').'>'.e($l).'</option>')
<form id="ff" method="GET" action="{{ route('home') }}#results">
  <input type="hidden" name="sort" value="{{ $st['sort'] }}"><input type="hidden" name="view" value="{{ $st['view'] }}">
  @foreach(['start', 'end'] as $k)@if($st[$k] !== '')<input type="hidden" name="{{ $k }}" value="{{ $st[$k] }}">@endif @endforeach
  <div class="field"><label for="f-q">Keyword</label><input class="input input-sm" id="f-q" name="q" value="{{ $st['q'] }}" placeholder="e.g. tiffany, tent"></div>
  <div class="field"><label for="f-qty">Quantity needed per day</label><input class="input input-sm" id="f-qty" type="number" min="1" name="qty" value="{{ $st['qty'] }}" placeholder="Any"></div>
  <label class="check mb-2"><input type="checkbox" name="avail" value="1" @checked($st['avail'] && $st['start']) @disabled(! $st['start'])> Available on selected dates</label>
  @unless($st['start'])<div class="hint mb-2" style="margin-top:-8px">Select rental dates above the products to filter and see available stock.</div>@endunless
  <div class="field"><label for="f-cat">Category</label><select class="input input-sm" id="f-cat" name="category">{!! $opt('', 'All categories', $st['category']) !!}@foreach($cats as $c){!! $opt($c->id, $c->name, $st['category']) !!}@endforeach</select></div>
  <div class="field"><label for="f-ev">Event type</label><select class="input input-sm" id="f-ev" name="event">{!! $opt('', 'Any event', $st['event']) !!}@foreach($events as $e){!! $opt($e, $e, $st['event']) !!}@endforeach</select></div>
  <div class="field"><label for="f-col">Color</label><select class="input input-sm" id="f-col" name="color">{!! $opt('', 'Any color', $st['color']) !!}@foreach($colors as $c){!! $opt($c, $c, $st['color']) !!}@endforeach</select></div>
  <div class="field"><label for="f-th">Theme</label><select class="input input-sm" id="f-th" name="theme">{!! $opt('', 'Any theme', $st['theme']) !!}@foreach($themesList as $c){!! $opt($c, $c, $st['theme']) !!}@endforeach</select></div>
  <div class="field"><label>Daily price (₱)</label><div class="range-fields"><input class="input input-sm" type="number" min="0" name="min" placeholder="Min" value="{{ $st['min'] }}" aria-label="Minimum price"><input class="input input-sm" type="number" min="0" name="max" placeholder="Max" value="{{ $st['max'] }}" aria-label="Maximum price"></div></div>
  <div class="field"><label for="f-sup">Supplier</label><select class="input input-sm" id="f-sup" name="supplier">{!! $opt('', 'All suppliers', $st['supplier']) !!}@foreach($sups as $s){!! $opt($s->id, $s->name, $st['supplier']) !!}@endforeach</select></div>
  <div class="field"><label for="f-city">Supplier location</label><select class="input input-sm" id="f-city" name="city">{!! $opt('', 'Anywhere', $st['city']) !!}@foreach($cities as $c){!! $opt($c, $c, $st['city']) !!}@endforeach</select></div>
  <div class="field"><label>Fulfillment</label><label class="check"><input type="checkbox" name="delivery" value="1" @checked($st['delivery'])> Delivery available</label><label class="check"><input type="checkbox" name="pickup" value="1" @checked($st['pickup'])> Pickup available</label></div>
  <div class="field"><label for="f-rt">Supplier rating</label><select class="input input-sm" id="f-rt" name="rating">{!! $opt('', 'Any rating', $st['rating']) !!}{!! $opt('4.5', '4.5★ & up', $st['rating']) !!}{!! $opt('4.7', '4.7★ & up', $st['rating']) !!}</select></div>
</form>

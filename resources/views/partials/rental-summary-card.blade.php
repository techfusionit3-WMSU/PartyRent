{{-- "About this rental" beside a rental's messages: the items, their dates and quantities, status and total.
     Needs $r (RentalRequest) and $side (customer|supplier). --}}
@php
  $f = $r->fin();
  $rentalLink = $side === 'customer' ? route('rentals.show', $r) : route('supplier.requests.show', $r);
@endphp
<aside class="card rental-sum" aria-label="About this rental">
  <div class="row between" style="gap:8px;align-items:flex-start">
    <div style="min-width:0"><div class="small muted">About this rental</div><div class="strong">{{ $r->code }}</div></div>
    <div class="row wrap" style="gap:4px;justify-content:flex-end">@include('partials.badges', ['status' => $r->status])@if($r->status !== 'pending')@include('partials.badges', ['pay' => $f['status']])@endif</div>
  </div>
  <div class="small muted mt-1">{{ $r->event_name }} · {{ fmt_range($r->start_date, $r->end_date) }}</div>

  <ul class="rental-sum-items">
    @foreach($r->lines as $l)
      @php
        $pkg = ($l['type'] ?? 'item') === 'package';
        $model = $pkg ? inv()->package($l['ref_id']) : inv()->product($l['ref_id']);
        $days = collect($l['days'] ?? [])->map(fn ($q) => (int) (is_array($q) ? ($q['qty'] ?? 0) : $q))->filter();
        $dates = $days->keys()->sort()->values();
        $qty = $days->max() ?: 0;
        $name = $model?->name ?? ($l['snap']['name'] ?? ($pkg ? 'Package' : 'Item'));
        $href = $model && $side === 'customer' ? ($pkg ? route('packages.show', $model) : route('products.show', $model)) : null;
      @endphp
      <li>
        <div class="thumb sm">@if($model)@include('partials.img', ['item' => $model, 'artSize' => '60%'])@else{{ $pkg ? '📦' : '🎈' }}@endif</div>
        <div style="min-width:0">
          <div class="strong ellipsis">@if($href)<a href="{{ $href }}">{{ $name }}</a>@else{{ $name }}@endif @if($pkg)<span class="badge b-pink plain">Package</span>@endif</div>
          <div class="small muted">{{ $qty }}× · {{ $dates->count() > 1 ? fmt_range($dates->first(), $dates->last()).' ('.$dates->count().' days)' : fmt_date($dates->first()) }}@if(! empty($l['time']['start'])) · {{ date('g:i A', strtotime($l['time']['start'])) }}–{{ date('g:i A', strtotime($l['time']['end'] ?? $l['time']['start'])) }}@endif</div>
        </div>
      </li>
    @endforeach
  </ul>

  <div class="row between" style="border-top:1px solid var(--line);padding-top:10px"><span class="small muted">Total</span><b>{{ money($r->pricing['total'] ?? $f['payable'] ?? 0) }}</b></div>
  <a class="btn btn-block btn-sm mt-2" href="{{ $rentalLink }}">Open the rental</a>
</aside>

<h3>{{ fmt_day($d) }}</h3>
<dl class="kv">
  <dt>Total stock</dt><dd>{{ $b['total'] }}</dd>
  <dt>Reserved</dt><dd>{{ $b['reserved'] }}</dd>
  <dt>Damaged / missing</dt><dd>{{ $b['damaged'] }} / {{ $b['missing'] }}</dd>
  <dt>Maintenance</dt><dd>{{ $b['maintenance'] }}</dd>
  <dt>Set aside</dt><dd>{{ $b['setaside'] }}</dd>
  <dt>Available</dt><dd><span class="avail-pill {{ $b['available'] ? 'ok' : 'none' }}">{{ $b['available'] }}</span></dd>
  <dt>Pending demand</dt><dd>{{ $b['pending'] }}@if($b['pending'] > $b['available']) <span class="badge b-red">exceeds available</span>@endif</dd>
</dl>
<div class="label mt-2 mb-1">Bookings this day</div>
@forelse($bookings as $x)
  @if($x['r'])
    <a class="row between" href="{{ route('supplier.requests.show', $x['r']) }}" style="padding:8px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><b>{{ $x['r']->code }}</b> · {{ $x['qty'] }} units<div class="small muted">{{ $x['r']->customer->name }} · {{ $x['r']->event_name }}</div></div><span class="badge {{ $x['kind'] === 'Accepted' ? 'b-sky' : 'b-gold' }}">{{ $x['kind'] }}</span></a>
  @endif
@empty
  <p class="small muted">No bookings.</p>
@endforelse
@if($b['holds'])
  <div class="label mt-2 mb-1">Holds</div>
  @foreach($b['holds'] as $h)<div class="small">{{ $h->type === 'setaside' ? 'Set aside' : 'Maintenance' }}: {{ $h->qty }} · {{ $h->note }}</div>@endforeach
@endif

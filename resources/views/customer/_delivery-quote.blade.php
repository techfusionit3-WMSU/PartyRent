{{-- Delivery fee breakdown (or why delivery isn't possible). $d = Pricing::deliveryQuote() result, $s = rental partner. --}}
@if(! $d)
@elseif($d['ok'])
  <div class="card card-flat soft" style="padding:12px 14px">
    <div class="row between"><b>{{ icon('truck', 'width:16px;vertical-align:-3px') }} Delivery to {{ $d['cityLabel'] }}</b><b class="price">{{ money($d['fee']) }}</b></div>
    @foreach($d['breakdown'] as $b)<div class="sum-line small"><span class="muted">{{ $b['label'] }}</span><span>{{ $b['amount'] < 0 ? '− '.money(-$b['amount']) : money($b['amount']) }}</span></div>@endforeach
    @if($d['trips'] > 1)<div class="small muted">{{ $d['trips'] }} separate trips because your dates are not consecutive.</div>@endif
  </div>
@elseif($d['code'] === 'no_city')
  <div class="alert alert-info">{{ icon('map') }}<div>{{ $d['error'] }}</div></div>
@else
  <div class="alert alert-danger">{{ icon('alert') }}<div><b>Delivery isn't available here.</b> {{ $d['error'] }}@if($s->fulfillment === 'both') You can switch to <b>pickup</b> at {{ $s->city }}.@endif</div></div>
@endif

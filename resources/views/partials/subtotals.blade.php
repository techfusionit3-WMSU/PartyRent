{{-- Rental / sale / service subtotals of a quote or a request's price snapshot ($p). Older snapshots only have itemsSubtotal. --}}
@if(isset($p['rentalSubtotal']) && (($p['salesSubtotal'] ?? 0) > 0 || ($p['servicesSubtotal'] ?? 0) > 0))
  @if($p['rentalSubtotal'] > 0)<div class="sum-line"><span class="muted">Rentals (returned after the event)</span><span>{{ money($p['rentalSubtotal']) }}</span></div>@endif
  @if($p['salesSubtotal'] > 0)<div class="sum-line"><span class="muted">Items for sale <span class="badge b-gold plain">not returned</span></span><span>{{ money($p['salesSubtotal']) }}</span></div>@endif
  @if($p['servicesSubtotal'] > 0)<div class="sum-line"><span class="muted">Services</span><span>{{ money($p['servicesSubtotal']) }}</span></div>@endif
@else
  <div class="sum-line"><span class="muted">Items & packages</span><span>{{ money($p['itemsSubtotal']) }}</span></div>
@endif

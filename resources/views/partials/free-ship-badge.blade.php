{{-- "Free Shipping" badge for rental partners in the Free Shipping program. $s = Rental Partner --}}
@if($s && \App\Domain\Pricing::freeShipActive($s))<span class="badge b-teal" title="Up to {{ money(setting('freeShipCap')) }} off delivery on items from {{ money(setting('freeShipMinSpend')) }}, paid by PartyRent">{{ icon('truck', 'width:12px;height:12px') }}Free Shipping</span>@endif

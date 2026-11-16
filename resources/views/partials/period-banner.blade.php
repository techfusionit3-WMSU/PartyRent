{{-- Shared.periodBanner (reference js/pages/shared.js) --}}
@php($rp = rental_period())
<div class="card date-bar mb-2"><span class="db-label">{{ icon('calendar') }}Your rental period</span>
  @if($rp)<div class="db-summary"><span>{{ \App\Support\RentalPeriod::rangeLabel($rp['start'], $rp['end']) }}</span><span class="db-sep">|</span><span class="badge b-purple plain">{{ \App\Support\RentalPeriod::durationLabel(\App\Support\RentalPeriod::days($rp)) }}</span></div>
  @else<span class="small muted">No rental dates selected — each item uses the dates set for it.</span>@endif
  <a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ $rp ? 'Change dates' : 'Select dates' }}</a></div>

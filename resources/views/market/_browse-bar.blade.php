{{-- Sticky rental date + Items/Packages + Filters + Sort bar (browse page). One date by default; "Multiple days" shows the end date. --}}
<div class="browse-sticky" id="browseBar">
  <form class="browse-bar" aria-label="Rental dates and sorting" method="GET" action="{{ route('home') }}#results" data-multiday>
    @foreach(array_diff_key($st, ['start' => 1, 'end' => 1, 'sort' => 1]) as $k => $v)@if($v !== '' && ! ($k === 'view' && $v === 'items'))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <div class="bb-dates">
      @php($bbNear = \App\Support\Near::get())
      <a class="bb-field bb-near" href="{{ route('near', ['back' => request()->fullUrl()]) }}" title="{{ __('Where you are renting: only shops that serve this place are shown') }}">{{ icon('map') }}<span class="ellipsis">{{ $bbNear ? $bbNear['label'] : __('Set your location') }}</span><span class="link">{{ __('Change') }}</span></a>
      <label class="bb-field">{{ icon('calendar') }}<span class="sr-only">Rental date</span><input class="input input-sm" type="date" name="start" min="{{ today_iso() }}" value="{{ $st['start'] }}" aria-label="{{ $nDays > 1 ? 'Start date' : 'Rental date' }}" data-md-start data-autosubmit></label>
      <span class="bb-end {{ $nDays > 1 ? '' : 'hidden' }}" data-md-end><span class="muted">to</span><input class="input input-sm" type="date" name="end" min="{{ $st['start'] ?: today_iso() }}" value="{{ $st['end'] }}" aria-label="End date" data-autosubmit></span>
      <label class="switch"><input type="checkbox" data-md-toggle @checked($nDays > 1)><span class="sw"></span>Multiple days</label>
      @if($st['start'])<span class="badge b-purple plain">{{ \App\Support\RentalPeriod::durationLabel($nDays) }}</span><a class="bb-clear" href="{{ $qs(['start' => '', 'end' => '', 'clear' => 1]) }}#results" title="Clear dates" aria-label="Clear dates">{{ icon('x') }}</a>@else<span class="small muted hide-sm">Pick a date to see available stock</span>@endif
    </div>
    <div class="bb-tools">
      <div class="seg"><a href="{{ $qs(['view' => 'items']) }}#results" class="{{ $st['view'] !== 'packages' ? 'active' : '' }}">Items</a><a href="{{ $qs(['view' => 'packages']) }}#results" class="{{ $st['view'] === 'packages' ? 'active' : '' }}">Packages</a></div>
      <button class="btn btn-sm" type="button" data-drawer="filterDrawer" aria-haspopup="dialog">{{ icon('filter') }}Filters @if($filterCount)<span class="filter-count">{{ $filterCount }}</span>@endif</button>
      <select class="input input-sm" name="sort" aria-label="Sort" data-autosubmit>@foreach([['recommended', 'Recommended'], ['price_asc', 'Lowest price'], ['price_desc', 'Highest price'], ['popular', 'Most popular'], ['recent', 'Recently added'], ['rating', 'Highest rated']] as [$v, $l])<option value="{{ $v }}" @selected($st['sort'] === $v)>{{ $l }}</option>@endforeach</select>
    </div>
  </form>
</div>

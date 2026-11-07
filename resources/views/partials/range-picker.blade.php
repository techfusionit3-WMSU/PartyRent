{{-- Report period picker. Needs $rg = Report::range(), $route, optional $csvRoute --}}
<form method="GET" action="{{ route($route) }}" class="row wrap" style="gap:6px">
  <div class="seg">@foreach(['month' => 'This month', '3m' => 'Last 3 months', 'year' => 'This year'] as $k => $l)<a href="{{ route($route, ['range' => $k]) }}"><button type="button" class="{{ $rg['range'] === $k ? 'active' : '' }}">{{ $l }}</button></a>@endforeach</div>
  <input type="hidden" name="range" value="custom">
  <input type="date" class="input input-sm" name="from" value="{{ $rg['from'] }}" style="width:auto" aria-label="From">
  <input type="date" class="input input-sm" name="to" value="{{ $rg['to'] }}" style="width:auto" aria-label="To">
  <button class="btn btn-sm">Apply</button>
  @isset($csvRoute)<a class="btn btn-sm btn-outline" href="{{ route($csvRoute, ['range' => $rg['range'], 'from' => $rg['from'], 'to' => $rg['to']]) }}">{{ icon('file') }}Download CSV</a>@endisset
</form>

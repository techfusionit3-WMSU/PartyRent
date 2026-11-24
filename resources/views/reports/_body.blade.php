{{-- The report itself: header, summary, tables. Shared by the screen view and the print page. Needs $report, $head, $f --}}
@php
  $cell = function ($v, $t) {
      if ($v === null || $v === '') return '—';
      return match ($t) { 'money' => money($v), 'int' => number_format((float) $v), 'date' => fmt_date($v), default => $v };
  };
@endphp
<header class="rp-head">
  <div class="rp-brand">
    <div class="rp-name">{{ $head['name'] }}</div>
    @foreach($head['lines'] as $l)<div class="rp-line">{{ $l }}</div>@endforeach
    @if($head['branch'])<div class="rp-line">Branch: {{ $head['branch'] }}</div>@endif
  </div>
  <div class="rp-meta">
    <h2 class="rp-title">{{ $report['title'] }}</h2>
    <div><b>Period:</b> {{ $head['period'] }}</div>
    <div class="rp-line">Generated {{ fmt_datetime($head['generated']) }} by {{ $head['by'] }}</div>
  </div>
</header>
@if($head['filters'])
  <div class="rp-filters"><b>Filters:</b> @foreach($head['filters'] as $k => $v)<span class="rp-chip">{{ $k }}: {{ $v }}</span>@endforeach</div>
@endif

@if($report['summary'])
  <div class="rp-summary">
    @foreach($report['summary'] as [$label, $value, $type])
      <div class="rp-fig"><div class="rp-fig-k">{{ $label }}</div><div class="rp-fig-v">{{ $cell($value, $type) }}</div></div>
    @endforeach
  </div>
@endif

@foreach($report['tables'] as $t)
  <section class="rp-section">
    <h3 class="rp-h">{{ $t['title'] }} <span class="rp-count">{{ count($t['rows']) }} {{ count($t['rows']) === 1 ? 'row' : 'rows' }}</span></h3>
    @if($t['rows'])
      <div class="table-wrap rp-wrap"><table class="table rp-table">
        <thead><tr>@foreach($t['cols'] as [$label, $type])<th class="{{ in_array($type, ['money', 'int']) ? 'num' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
          @foreach($t['rows'] as $row)
            <tr>@foreach($t['cols'] as $i => [$label, $type])<td class="{{ in_array($type, ['money', 'int']) ? 'num' : '' }}" data-label="{{ $label }}">{{ $cell($row[$i] ?? null, $type) }}</td>@endforeach</tr>
          @endforeach
        </tbody>
        @if($t['total'])
          <tfoot><tr>@foreach($t['cols'] as $i => [$label, $type])<td class="{{ in_array($type, ['money', 'int']) ? 'num' : '' }}" data-label="{{ $label }}">{{ in_array($type, ['money', 'int']) ? $cell($t['total'][$i], $type) : $t['total'][$i] }}</td>@endforeach</tr></tfoot>
        @endif
      </table></div>
    @else
      <p class="rp-empty">{{ $t['empty'] }}</p>
    @endif
    @if($t['note'])<p class="rp-note">{{ $t['note'] }}</p>@endif
  </section>
@endforeach
@foreach($report['notes'] as $n)<p class="rp-note">{{ $n }}</p>@endforeach

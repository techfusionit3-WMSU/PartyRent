{{-- Simple accessible horizontal bar chart. $rows = [['label' => .., 'value' => ..]], $money = bool, $color --}}
@php($max = max(1, collect($rows)->max('value') ?: 1))
@if(collect($rows)->isEmpty() || collect($rows)->sum('value') == 0)
  <p class="small muted">No data yet.</p>
@else
  <div class="stack-sm" role="list">
    @foreach($rows as $row)
      <div role="listitem" style="display:grid;grid-template-columns:minmax(80px,38%) 1fr auto;gap:10px;align-items:center">
        <span class="small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
        <span style="height:12px;border-radius:999px;background:var(--line);overflow:hidden"><span style="display:block;height:100%;width:{{ round($row['value'] / $max * 100, 1) }}%;background:{{ $color ?? 'var(--brand)' }};border-radius:999px"></span></span>
        <b class="small">{{ ! empty($money) ? money_short($row['value']) : number_format($row['value']) }}</b>
      </div>
    @endforeach
  </div>
@endif

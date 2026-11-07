{{-- Vertical column chart. $rows = [['label' => .., 'value' => ..]], $money = bool, $color --}}
@php($rows = collect($rows))
@php($max = max(1, $rows->max('value') ?: 1))
@if($rows->sum('value') == 0)
  <p class="small muted">No data for this period.</p>
@else
  <div role="img" aria-label="{{ $label ?? 'Chart' }}: {{ $rows->map(fn ($r) => $r['label'].' '.(! empty($money) ? money_short($r['value']) : $r['value']))->implode(', ') }}"
       style="display:flex;align-items:flex-end;gap:{{ $rows->count() > 20 ? 2 : 8 }}px;height:{{ $height ?? 160 }}px;padding-top:18px">
    @foreach($rows as $row)
      @php($h = round($row['value'] / $max * 100, 1))
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%" title="{{ $row['label'] }}: {{ ! empty($money) ? money($row['value']) : number_format($row['value']) }}">
        @if($rows->count() <= 12)<span class="small strong" style="font-size:.7rem;white-space:nowrap">{{ $row['value'] ? (! empty($money) ? money_short($row['value']) : number_format($row['value'])) : '' }}</span>@endif
        <span style="display:block;width:100%;max-width:42px;height:{{ max($h, $row['value'] ? 2 : 0) }}%;background:{{ $color ?? 'var(--brand)' }};border-radius:6px 6px 2px 2px"></span>
      </div>
    @endforeach
  </div>
  <div style="display:flex;gap:{{ $rows->count() > 20 ? 2 : 8 }}px;margin-top:6px">
    @foreach($rows as $i => $row)<span class="small muted" style="flex:1;min-width:0;text-align:center;font-size:.68rem;overflow:hidden;white-space:nowrap">{{ $rows->count() > 16 && $i % 3 ? '' : $row['label'] }}</span>@endforeach
  </div>
@endif

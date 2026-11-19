{{-- Type-to-add tags with optional suggestions (public/js/app.js "tag inputs").
     name: field name (sent as name[]) · values: selected · suggest: suggestions · swatch: color dots · placeholder --}}
@php
    $swatch = $swatch ?? false;
    $values = array_values(array_filter((array) ($values ?? []), fn ($v) => trim((string) $v) !== ''));
    $hex = $swatch ? collect($suggest)->mapWithKeys(fn ($s) => [$s => color_hex($s)])->all() : [];
    $sel = array_map('mb_strtolower', $values);
@endphp
<div class="tag-input" data-tags data-name="{{ $name }}" data-max="15" @if($swatch) data-hex='@json($hex)' @endif>
  <div class="tag-box">
    @foreach($values as $v)
      <span class="chip active tag" data-v="{{ $v }}">@if($swatch)<span class="swatch" style="background:{{ color_hex($v) }}"></span>@endif{{ $v }}<input type="hidden" name="{{ $name }}[]" value="{{ $v }}"><button type="button" class="tag-x" data-tag-rm aria-label="Remove {{ $v }}">×</button></span>
    @endforeach
    <input class="tag-type" type="text" maxlength="40" autocomplete="off" placeholder="{{ $placeholder ?? 'Type and press Enter' }}" aria-label="Add {{ $name }}">
  </div>
  <div class="tag-sugs">
    <span class="small muted tag-sugs-label">Suggestions:</span>
    @foreach($suggest as $s)
      <button type="button" class="chip tag-sug" data-tag-add="{{ $s }}" @if(in_array(mb_strtolower($s), $sel, true)) hidden @endif>@if($swatch)<span class="swatch" style="background:{{ color_hex($s) }}"></span>@endif{{ $s }}</button>
    @endforeach
    <button type="button" class="chip tag-new" data-tag-new hidden></button>
  </div>
</div>

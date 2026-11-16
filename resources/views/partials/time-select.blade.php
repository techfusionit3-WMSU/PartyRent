{{-- 12-hour time <select> within the supplier's rental hours (Slots.select). $name, $value, $s (Supplier), $attrs --}}
@php($opts = \App\Domain\Slots::options($s))
@php($value && ! in_array($value, $opts, true) ? $opts[] = $value : null)
@php(sort($opts))
<select class="input" name="{{ $name }}" {!! $attrs ?? '' !!}>@foreach($opts as $o)<option value="{{ $o }}" @selected($o === $value)>{{ \App\Domain\Slots::fmt12($o) }}</option>@endforeach</select>

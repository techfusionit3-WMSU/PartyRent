@php($tag = ! empty($href) ? 'a' : 'div')
<{{ $tag }} class="stat c-{{ $color ?? 'pink' }}" @if(! empty($href)) href="{{ $href }}" @endif><div class="ico">{{ icon($icon ?? 'chart') }}</div><div class="k">{{ $label }}</div><div class="v">{{ $value }}</div>@if(! empty($sub))<div class="s">{!! $sub !!}</div>@endif</{{ $tag }}>

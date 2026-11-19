{{-- First-time guides: $guide (shop setup checklist) and/or $welcome (employee welcome card). --}}
@if(! empty($guide))
  <div class="card guide mb-2" id="guide-shop">
    <div class="row between wrap"><div><h3 class="mb-0">{{ __('Get your shop ready') }}</h3><div class="small muted">{{ __(':done of :total done. Customers can rent from you once these are set.', ['done' => $guide['done'], 'total' => $guide['total']]) }}</div></div>
      <form method="POST" action="{{ route('ui.dismiss') }}">@csrf<input type="hidden" name="key" value="guide.shop"><button class="btn btn-sm btn-ghost">{{ __('Hide this') }}</button></form></div>
    <div class="guide-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $guide['total'] }}" aria-valuenow="{{ $guide['done'] }}" aria-label="{{ __('Setup progress') }}"><span style="width:{{ round($guide['done'] / $guide['total'] * 100) }}%"></span></div>
    <ol class="guide-list">
      @foreach($guide['items'] as $i => [$t, $sub, $ok, $url])
        <li class="{{ $ok ? 'done' : '' }}"><span class="guide-dot" aria-hidden="true">{{ $ok ? '✓' : $i + 1 }}</span><span class="grow"><span class="guide-txt"><b>{{ $t }}</b></span><span class="small muted" style="display:block">{{ $sub }}</span></span>@unless($ok)<a class="btn btn-sm" href="{{ $url }}">{{ __('Do it') }}</a>@endunless<span class="sr-only">{{ $ok ? __('done') : __('to do') }}</span></li>
      @endforeach
    </ol>
  </div>
@endif
@if(! empty($welcome))
  <div class="card guide mb-2">
    <div class="row between wrap" style="align-items:flex-start"><div><h3 class="mb-0">👋 {{ $welcome['title'] }}</h3>
      <p class="mb-1 mt-1">{{ __("You're working as :role. This is your :module module: :about", ['role' => $welcome['role'], 'module' => $welcome['module'], 'about' => mb_strtolower($welcome['about'])]) }}</p>
      <ul class="small mb-0" style="padding-left:18px">
        <li>{{ __('Your tasks show under "Needs you" with one button each.') }}</li>
        @if($welcome['many'])<li>{{ __('You have more than one role: switch modules under "Your modules" in the menu.') }}</li>@endif
        <li>{{ __('To rent something for yourself, choose "Switch to Customer Mode" in your profile menu.') }}</li>
      </ul></div>
      <form method="POST" action="{{ route('ui.dismiss') }}">@csrf<input type="hidden" name="key" value="{{ $welcome['key'] }}"><button class="btn btn-sm">{{ __('Got it') }}</button></form></div>
  </div>
@endif

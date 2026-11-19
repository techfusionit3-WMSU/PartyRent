{{-- "Needs you": $tasks = App\Domain\Tasks::forModule(user, module). One button per task. --}}
<div class="card needs-you">
  <div class="card-head"><h3>{{ __('Needs you') }}</h3>@if($tasks)<span class="badge b-pink">{{ count($tasks) }}</span>@endif</div>
  @forelse($tasks as [$ic, $t, $sub, $acts])
    <div class="ny-item">
      <span class="ny-ico" aria-hidden="true">{{ icon($ic) }}</span>
      <div class="ny-body"><b>{{ $t }}</b><span class="small muted">{{ $sub }}</span></div>
      <div class="ny-act">
        @foreach($acts as $a)
          @if(isset($a['post']))
            <form method="POST" action="{{ $a['post'] }}" data-confirm="{{ $a['confirm'] ?? '' }}" data-confirm-title="{{ $a['label'] }}?" data-confirm-ok="{{ $a['label'] }}">@csrf @foreach($a['fields'] ?? [] as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach<button class="btn btn-sm btn-primary">{{ $a['label'] }}</button></form>
          @else
            <a class="btn btn-sm" href="{{ $a['url'] }}">{{ $a['label'] }}</a>
          @endif
        @endforeach
      </div>
    </div>
  @empty
    <p class="small muted mb-0">{{ __('Nothing needs you right now. New tasks show up here.') }}</p>
  @endforelse
</div>

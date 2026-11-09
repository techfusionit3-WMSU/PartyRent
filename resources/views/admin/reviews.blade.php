@extends('layouts.dash', ['title' => 'Review Moderation', 'nav' => 'reviews'])

@section('content')
<div class="page-head"><div><h1>Review moderation</h1><p>Reviews reported by customers or shops. Hiding a review removes it from the shop and item pages and recalculates their ratings. The customer is notified.</p></div></div>

<div class="card">
  <div class="tabs">@foreach(['reported' => 'Reported', 'hidden' => 'Hidden', 'all' => 'All reviews'] as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.reviews', ['tab' => $k]) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '🛡️', 'title' => $tab === 'reported' ? 'No reported reviews' : 'Nothing here', 'text' => $tab === 'reported' ? 'When someone reports a review it shows up here.' : ''])
  @else
    <div class="stack mt-2">
    @foreach($list as $rv)
      @php($open = $rv->reports->where('status', 'open'))
      <div class="card card-flat">
        @include('partials.review', ['rv' => $rv, 'showShop' => true, 'noReport' => true])
        @if($rv->reports->isNotEmpty())
          <div class="mt-2"><b class="small">Reports ({{ $open->count() }} open of {{ $rv->reports->count() }})</b>
            <ul class="small mb-0" style="padding-left:18px">@foreach($rv->reports as $rp)<li><b>{{ $rp->reasonLabel() }}</b> — {{ $rp->reporter?->name }}{{ $rp->details ? ': “'.$rp->details.'”' : '' }} <span class="muted">· {{ fmt_date($rp->created_at) }} · {{ $rp->status }}</span></li>@endforeach</ul>
          </div>
        @endif
        <div class="row wrap mt-2">
          @if($rv->hidden_at)
            <form method="POST" action="{{ route('admin.reviews.moderate', $rv) }}">@csrf<input type="hidden" name="action" value="restore"><button class="btn btn-sm">{{ icon('eye') }}Restore review</button></form>
          @else
            <button type="button" class="btn btn-sm btn-danger" data-modal="hide-{{ $rv->id }}">Hide review</button>
            @if($open->isNotEmpty())<form method="POST" action="{{ route('admin.reviews.moderate', $rv) }}">@csrf<input type="hidden" name="action" value="dismiss"><button class="btn btn-sm">{{ icon('check') }}Keep it — dismiss reports</button></form>@endif
          @endif
        </div>
      </div>
      @unless($rv->hidden_at)
      <div class="modal-backdrop hidden" id="hide-{{ $rv->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Hide this review?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
        <form method="POST" action="{{ route('admin.reviews.moderate', $rv) }}">@csrf<input type="hidden" name="action" value="hide"><div class="modal-body">
          <p class="small">It will no longer be shown or counted in {{ $rv->supplier->name }}'s rating. The customer sees your reason.</p>
          <div class="field"><label for="hr-{{ $rv->id }}">Reason</label><input class="input" id="hr-{{ $rv->id }}" name="reason" required maxlength="300" value="{{ $open->first()?->reasonLabel() }}"></div>
        </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Hide review</button></div></form>
      </div></div>
      @endunless
    @endforeach
    </div>
  @endif
</div>
@include('partials.media-lightbox')
@endsection

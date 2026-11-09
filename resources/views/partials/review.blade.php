{{-- One customer review. Vars: $rv (Review), $showItem (bool, default true), $canReply (bool), $showShop (bool) --}}
@php
    $me = auth()->user();
    // Anyone signed in except the author can report — including the reviewed shop (e.g. a fake review).
    $canReport = $me && $me->id !== $rv->customer_id && ! $rv->hidden_at && empty($noReport);
    $media = $rv->mediaItems();
@endphp
<article class="review card card-flat" id="review-{{ $rv->id }}">
  <div class="row between top wrap">
    <div class="row">
      <span class="avatar">{{ $rv->customer?->initials() }}</span>
      <div>
        <b>{{ $rv->authorName() }}</b>
        @if($rv->isVerified())<span class="badge b-green plain review-verified" title="This customer rented through PartyRent and the rental was completed">{{ icon('check', 'width:12px;height:12px') }}Verified rental</span>@endif
        <div class="small muted">
          @if(($showShop ?? false) && $rv->supplier)<a href="{{ route('suppliers.show', $rv->supplier) }}">{{ $rv->supplier->name }}</a> · @endif
          @if(($showItem ?? true) && $rv->itemName())Rented: {{ $rv->itemName() }} · @endif
          {{ fmt_date($rv->created_at) }}
        </div>
      </div>
    </div>
    <span class="stars" aria-label="{{ $rv->rating }} out of 5 stars">{{ stars($rv->rating) }}</span>
  </div>
  <p class="mt-1 mb-0 review-text">{{ $rv->comment }}</p>

  @if($media)
    <div class="review-media" role="list" aria-label="Customer photos and videos">
      @foreach($media as $i => $m)
        <button type="button" class="review-thumb" role="listitem" data-media-group="rv{{ $rv->id }}" data-media-type="{{ $m['type'] }}" data-media-src="{{ $m['url'] }}" aria-label="Open {{ $m['type'] === 'video' ? 'video' : 'photo '.($i + 1) }} from {{ $rv->authorName() }}">
          @if($m['type'] === 'video')
            <video src="{{ $m['url'] }}#t=0.1" preload="metadata" muted playsinline></video><span class="play">▶</span>
          @else
            <img src="{{ $m['url'] }}" alt="Photo of {{ $rv->itemName() ?: 'the rental' }} from {{ $rv->authorName() }}" loading="lazy">
          @endif
        </button>
      @endforeach
    </div>
  @endif

  @if($rv->hidden_at)
    <div class="alert alert-warn mt-1">{{ icon('eye') }}<div><b>Hidden from customers</b>{{ $rv->hidden_reason ? ' — '.$rv->hidden_reason : '' }}</div></div>
  @endif

  @if($rv->reply)
    <div class="review-reply mt-1"><div class="small strong">{{ icon('store', 'width:14px;height:14px') }} Response from {{ $rv->supplier?->name }} <span class="muted" style="font-weight:400">· {{ fmt_date($rv->replied_at) }}</span></div><p class="mb-0 small">{{ $rv->reply }}</p></div>
  @elseif($canReply ?? false)
    <form method="POST" action="{{ route('supplier.reviews.reply', $rv) }}" class="mt-1">@csrf
      <div class="field mb-1"><label for="reply-{{ $rv->id }}" class="small">Reply publicly (one reply per review)</label><textarea class="input" id="reply-{{ $rv->id }}" name="reply" rows="2" required minlength="5" maxlength="1000" placeholder="Thank the customer or address their concern…"></textarea></div>
      <button class="btn btn-sm btn-primary">{{ icon('message') }}Post reply</button>
    </form>
  @endif

  @if($canReport && ! ($canReply ?? false))
    <div class="mt-1"><button type="button" class="btn btn-xs btn-ghost" data-modal="report-{{ $rv->id }}">{{ icon('flag', 'width:13px;height:13px') }}Report</button></div>
    <div class="modal-backdrop hidden" id="report-{{ $rv->id }}"><div class="modal sm" role="dialog" aria-modal="true" aria-label="Report review"><div class="modal-head"><h3>Report this review</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
      <form method="POST" action="{{ route('reviews.report', $rv) }}">@csrf<div class="modal-body">
        <p class="small muted">Tell the PartyRent team what's wrong. The reviewer won't see who reported it.</p>
        <div class="field"><label for="reason-{{ $rv->id }}">Reason</label><select class="input" id="reason-{{ $rv->id }}" name="reason" required>@foreach(\App\Models\ReviewReport::REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label for="details-{{ $rv->id }}">Details <span class="muted">(optional)</span></label><textarea class="input" id="details-{{ $rv->id }}" name="details" maxlength="500" rows="3"></textarea></div>
      </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Send report</button></div></form>
    </div></div>
  @endif
</article>

{{-- Rating summary + filters + review list.
     Vars: $reviews (visible reviews), $showItem (bool), $emptyText (string), $title (string|null) --}}
@php
    $sum = \App\Domain\Reviews::summary($reviews);
    $rf = request()->query('rf', 'all');
    $shown = \App\Domain\Reviews::filter($reviews, $rf);
    $link = fn ($v) => request()->fullUrlWithQuery(['rf' => $v === 'all' ? null : $v]).'#reviews';
@endphp
<section class="reviews-block" id="reviews">
  @if(! empty($title))<h2>{{ $title }}</h2>@endif
  @if($sum['count'] === 0)
    @include('partials.empty', ['emoji' => '⭐', 'title' => 'No reviews yet', 'text' => $emptyText ?? 'Reviews come only from customers who completed a rental.'])
  @else
    <div class="card review-summary">
      <div class="rs-score">
        <div class="rs-avg">{{ number_format($sum['avg'], 1) }}</div>
        <div class="stars">{{ stars($sum['avg']) }}</div>
        <div class="small muted">{{ $sum['count'] }} {{ $sum['count'] === 1 ? 'review' : 'reviews' }} from verified renters</div>
      </div>
      <div class="rs-bars">
        @foreach($sum['dist'] as $star => $n)
          @php($pct = $sum['count'] ? round($n / $sum['count'] * 100) : 0)
          <a class="rs-bar {{ $rf === (string) $star ? 'on' : '' }}" href="{{ $link((string) $star) }}" aria-label="{{ $star }} stars: {{ $n }} reviews">
            <span>{{ $star }}★</span><span class="rs-track"><span style="width:{{ $pct }}%"></span></span><span class="muted">{{ $n }}</span>
          </a>
        @endforeach
      </div>
    </div>
    <div class="row wrap gap-sm mt-2 mb-2" role="group" aria-label="Filter reviews">
      <a class="chip {{ $rf === 'all' ? 'active' : '' }}" href="{{ $link('all') }}">All ({{ $sum['count'] }})</a>
      <a class="chip {{ $rf === 'media' ? 'active' : '' }}" href="{{ $link('media') }}">{{ icon('image', 'width:14px;height:14px') }} With photos/videos ({{ $sum['media'] }})</a>
      @foreach([5, 4, 3, 2, 1] as $star)<a class="chip {{ $rf === (string) $star ? 'active' : '' }}" href="{{ $link((string) $star) }}">{{ $star }}★ ({{ $sum['dist'][$star] }})</a>@endforeach
    </div>
    @if($shown->isEmpty())
      <p class="muted">No reviews match this filter. <a href="{{ $link('all') }}">Show all reviews</a></p>
    @else
      <div class="stack">@foreach($shown as $rv)@include('partials.review', ['rv' => $rv, 'showItem' => $showItem ?? true])@endforeach</div>
    @endif
  @endif
</section>
@include('partials.media-lightbox')

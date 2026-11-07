<section class="section"><div class="section-title"><h2>{{ $title }}</h2>@isset($more)<a class="small strong" href="{{ $more }}">See more</a>@endisset</div>
  <div class="product-grid">@foreach($items as $p)@include('partials.product-card', ['p' => $p, 'dates' => $dates ?? []])@endforeach</div>
</section>

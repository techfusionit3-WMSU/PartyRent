@extends('layouts.dash', ['title' => 'Customer Reviews', 'nav' => 'reviews'])

@section('content')
<div class="page-head"><div><h1>Customer reviews</h1><p>Every review comes from a customer who completed a rental with {{ $sup->name }}. Reply once, publicly — your reply appears under the review on your shop and item pages.</p></div><a class="btn" href="{{ route('suppliers.show', [$sup, 'tab' => 'reviews']) }}#reviews">{{ icon('eye') }}View on shop page</a></div>

<div class="stats mb-3">
  <div class="stat c-gold"><div class="ico">{{ icon('star') }}</div><div class="k">Shop rating</div><div class="v">{{ $summary['count'] ? number_format($summary['avg'], 1) : '—' }}</div><div class="s">{{ rating_text($sup) }}</div></div>
  <div class="stat c-pink"><div class="ico">{{ icon('message') }}</div><div class="k">Waiting for your reply</div><div class="v">{{ $counts['unreplied'] }}</div></div>
  <div class="stat c-purple"><div class="ico">{{ icon('image') }}</div><div class="k">With photos/videos</div><div class="v">{{ $summary['media'] }}</div></div>
  <div class="stat c-teal"><div class="ico">{{ icon('check') }}</div><div class="k">4–5 star reviews</div><div class="v">{{ $summary['count'] ? round(($summary['dist'][5] + $summary['dist'][4]) / $summary['count'] * 100) : 0 }}%</div></div>
</div>

<div class="card">
  <div class="tabs">@foreach(['unreplied' => 'Needs reply', 'all' => 'All visible', 'hidden' => 'Hidden by PartyRent'] as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('supplier.reviews', ['tab' => $k]) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '⭐', 'title' => $tab === 'unreplied' ? 'You\'re all caught up' : 'No reviews here', 'text' => $tab === 'unreplied' ? 'Every review has a reply.' : 'Reviews appear after customers complete a rental and rate it.'])
  @else
    <div class="stack mt-2">@foreach($list as $rv)@include('partials.review', ['rv' => $rv->setRelation('supplier', $sup), 'canReply' => $tab !== 'hidden'])@endforeach</div>
  @endif
</div>
@include('partials.media-lightbox')
@endsection

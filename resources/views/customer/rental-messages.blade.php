@extends('layouts.market', ['title' => 'Messages · '.$r->code, 'nav' => 'rentals'])

@section('content')
<div class="crumbs"><a href="{{ route('rentals.index') }}">My Rentals</a> / <a href="{{ route('rentals.show', $r) }}">{{ $r->code }}</a> / Messages</div>
<div class="page-head"><div><h1>Messages with {{ $r->supplier->name }}</h1><p>About your rental <b>{{ $r->code }}</b> · {{ $r->event_name }}</p></div>
  <div class="page-head-side"><a class="btn btn-sm" href="{{ route('rentals.show', $r) }}">{{ icon('left') }}Back to the rental</a></div></div>
<div class="chat-layout">
  <div class="chat-main">@include('partials.request-chat', ['chatSide' => 'customer', 'chatPage' => true])</div>
  @include('partials.rental-summary-card', ['r' => $r, 'side' => 'customer'])
</div>
@endsection

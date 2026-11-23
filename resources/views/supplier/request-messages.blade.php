@extends('layouts.dash', ['title' => 'Messages · '.$r->code, 'nav' => 'requests'])

@section('content')
<div class="crumbs"><a href="{{ route('supplier.requests') }}">Rentals</a> / <a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a> / Messages</div>
<div class="page-head"><div><h1>Messages with {{ $r->customer->name }}</h1><p>About rental <b>{{ $r->code }}</b> · {{ $r->event_name }}</p></div>
  <div class="page-head-side"><a class="btn btn-sm" href="{{ route('supplier.requests.show', $r) }}">{{ icon('left') }}Back to the rental</a></div></div>
<div class="chat-layout">
  <div class="chat-main">@include('partials.request-chat', ['chatSide' => 'supplier', 'chatPage' => true])</div>
  @include('partials.rental-summary-card', ['r' => $r, 'side' => 'supplier'])
</div>
@endsection

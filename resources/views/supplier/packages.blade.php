@extends('layouts.dash', ['title' => 'Packages', 'nav' => 'packages'])

@section('content')
<div class="page-head"><div><h1>Packages</h1><p>Bundles of your rental items, consumables and services. Availability always comes from the stock of what's inside. Customers see them in this order.</p></div><a class="btn btn-primary" href="{{ route('supplier.packages.create') }}">{{ icon('plus') }}New package</a></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet', 'text' => 'Bundle items that customers often rent together, add services and let them pick options.'])</div>
@else
  <div class="grid grid-2">
    @foreach($list as $n => $k)
      @php($def = \App\Domain\Packages::def($k))
      @php($pp = \App\Domain\Packages::price($def))
      <div class="card">
        <div class="row between top">
          <div><div style="font-size:1.8rem">{{ $k->emoji }}</div><b>{{ $k->name }}</b>
            <span class="badge {{ $k->is_draft ? 'b-gray' : ($k->active ? 'b-green' : 'b-gold') }}">{{ $k->statusLabel() }}</span>
            <div class="small muted">{{ count($def['components']) }} components · {{ money($pp['package']) }}{{ $pp['once'] > 0 && $pp['perDay'] <= 0 ? '' : '/day' }}{{ $pp['savings'] > 0 ? ' · saves customers '.money($pp['savings']) : '' }} · {{ \App\Domain\Packages::MODES[$def['pricing']['mode']] }}</div>
          </div>
          <div class="btn-group">
            <form method="POST" action="{{ route('supplier.packages.move', $k) }}">@csrf<input type="hidden" name="dir" value="up"><button class="btn btn-xs btn-ghost" aria-label="Move up" @disabled($n === 0)>↑</button></form>
            <form method="POST" action="{{ route('supplier.packages.move', $k) }}">@csrf<input type="hidden" name="dir" value="down"><button class="btn btn-xs btn-ghost" aria-label="Move down" @disabled($n === $list->count() - 1)>↓</button></form>
          </div>
        </div>
        <p class="small mt-1 mb-1">{{ $k->description }}</p>
        <div class="small muted">@foreach(\App\Domain\Packages::describe($def) as $line)<div>{{ $line }}</div>@endforeach</div>
        @unless($k->is_draft)<div class="small mt-1">Available today: <b>{{ inv()->packageAvailableOn($k->id, today_iso()) }}</b></div>@endunless
        <div class="btn-group mt-2">
          <a class="btn btn-sm" href="{{ route('supplier.packages.edit', $k) }}">{{ icon('edit') }}Edit</a>
          @unless($k->is_draft)<form method="POST" action="{{ route('supplier.packages.active', $k) }}">@csrf<button class="btn btn-sm btn-ghost">{{ $k->active ? 'Hide' : 'Show' }}</button></form>@endunless
          <form method="POST" action="{{ route('supplier.packages.duplicate', $k) }}">@csrf<button class="btn btn-sm btn-ghost">Duplicate</button></form>
          <form method="POST" action="{{ route('supplier.packages.delete', $k) }}" data-confirm="Delete {{ $k->name }}? It disappears from customers' Rental Lists. Requests already booked keep their copy." data-confirm-title="Delete package?" data-confirm-ok="Delete" data-danger>@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" aria-label="Delete package">{{ icon('trash') }}</button></form>
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection

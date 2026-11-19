@extends('layouts.dash', ['title' => 'Rental Packages', 'nav' => 'packages'])

{{-- Reference SP.packages (+ app: order, duplicate, delete, drafts, options) --}}
@php($today = today_iso())
@php($next30 = array_map(fn ($i) => add_days($today, $i), range(0, 29)))
@section('content')
<div class="page-head"><div><h1>Rental Packages</h1><p>Bundles of your items at a fixed price. Reserving a package deducts each included item from inventory.</p></div><a class="btn btn-primary" href="{{ route('supplier.packages.create') }}">{{ icon('plus') }}Create package</a></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet', 'text' => 'Bundle items that customers often rent together.'])</div>
@else
  <div class="grid grid-2">
    @foreach($list as $n => $k)
      @php($def = \App\Domain\Packages::def($k))
      @php($pp = \App\Domain\Packages::price($def))
      @php($inc = array_values(array_filter($pp['rows'], fn ($r) => $r['group'] === 'included')))
      @php($hidden = $k->status === 'hidden')
      @php($limiting = collect($inc)->filter(fn ($r) => isset($r['productId']) && $r['qty'] > 0)->map(fn ($r) => ['name' => $r['name'], 'a' => intdiv(inv()->availableOn($r['productId'], $today), (int) max(1, $r['qty']))])->sortBy('a')->first())
      <div class="card">
        <div class="row top">
          @include('partials.thumb', ['item' => $k])
          <div class="grow">
            <div class="row between"><b>{{ $k->emoji }} {{ $k->name }}</b><span class="badge {{ $hidden ? 'b-red' : ($k->is_draft ? 'b-gray' : ($k->active ? 'b-green' : 'b-gray')) }}">{{ $hidden ? 'Hidden by admin' : ($k->is_draft ? 'Draft' : ($k->active ? 'Active' : 'Inactive')) }}</span></div>
            <div class="small muted">{{ $k->description }}</div>
            @if($hidden)<div class="small" style="color:var(--red)">Hidden by a PartyRent admin: {{ $k->hidden_reason }}. It cannot be republished.</div>@endif
            <div class="mt-1"><span class="price">{{ money($pp['package']) }}</span> <span class="small muted">{{ $pp['once'] > 0 && $pp['perDay'] <= 0 ? '' : '/ day · ' }}items separately {{ money($pp['separate']) }}</span></div>
            @if($k->max_guests)<div class="small muted">Good for up to {{ $k->max_guests }} guests</div>@endif
          </div>
        </div>
        <div class="mt-1 small">@foreach($inc as $r)<span class="tag">{{ $r['qty'] }}× {{ $r['name'] }}</span> @endforeach</div>
        @if(count($def['components']) > count($inc))<div class="small muted mt-1">@foreach(array_slice(\App\Domain\Packages::describe($def), 1) as $line)<div>{{ $line }}</div>@endforeach</div>@endif
        <div class="row between mt-2 wrap">
          <div class="small">@unless($k->is_draft)<b>{{ inv()->packageAvailableOn($k->id, $today) }}</b> available today · min <b>{{ min(array_map(fn ($d) => inv()->packageAvailableOn($k->id, $d), $next30)) }}</b> next 30 days @if($limiting)<div class="muted">Limited by {{ $limiting['name'] }}</div>@endif @endunless</div>
          <div class="btn-group">
            <a class="btn btn-xs" href="{{ route('supplier.packages.edit', $k) }}">{{ icon('edit') }}Edit</a>
            @unless($k->is_draft || $hidden)<form method="POST" action="{{ route('supplier.packages.active', $k) }}">@csrf<button class="btn btn-xs">{{ $k->active ? 'Deactivate' : 'Activate' }}</button></form>@endunless
            <form method="POST" action="{{ route('supplier.packages.duplicate', $k) }}">@csrf<button class="btn btn-xs btn-ghost">Duplicate</button></form>
            <form method="POST" action="{{ route('supplier.packages.move', $k) }}">@csrf<input type="hidden" name="dir" value="up"><button class="btn btn-xs btn-ghost" aria-label="Move up" title="Show earlier to customers" @disabled($n === 0)>↑</button></form>
            <form method="POST" action="{{ route('supplier.packages.move', $k) }}">@csrf<input type="hidden" name="dir" value="down"><button class="btn btn-xs btn-ghost" aria-label="Move down" title="Show later to customers" @disabled($n === $list->count() - 1)>↓</button></form>
            <form method="POST" action="{{ route('supplier.packages.delete', $k) }}" data-confirm="Delete {{ $k->name }}? It disappears from customers' Rental Lists. Requests already booked keep their copy.{{ collect(\App\Domain\Packages::productIds($def))->contains(fn ($id) => inv()->product($id)?->package_only) ? ' Its package-only items stay in your inventory, unlisted (moved to Archived).' : '' }}" data-confirm-title="Delete package?" data-confirm-ok="Delete" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost" aria-label="Delete package">{{ icon('trash') }}</button></form>
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection

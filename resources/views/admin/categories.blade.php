@extends('layouts.dash', ['title' => 'Categories', 'nav' => 'categories'])

@section('content')
<div class="page-head"><div><h1>Categories</h1><p>Inactive categories disappear from the marketplace menus; their products stay listed under browse and search.</p></div><button class="btn btn-primary" data-modal="catNew">{{ icon('plus') }}New category</button></div>
@if($pending->isNotEmpty())
<div class="card mb-3"><h3>Requested by suppliers <span class="badge b-gold">{{ $pending->count() }}</span></h3>
  <p class="small muted">Suppliers typed these categories. Their items show under Other Party Supplies until you approve the category, merge it into an existing one, or reject it.</p>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Requested category</th><th>Items waiting</th><th>Requested by</th><th></th></tr></thead><tbody>
  @foreach($pending as $c)
    @php($items = $waiting[$c->id] ?? collect())
    <tr>
      <td data-label="Requested category"><b>{{ $c->name }}</b><div class="small muted">{{ fmt_date($c->created_at?->toDateString()) }}</div></td>
      <td data-label="Items waiting">@if($items->isEmpty())<span class="muted small">None right now</span>@else<span class="small">{{ $items->take(3)->map(fn ($p) => $p->name.' ('.($p->supplier->name ?? '—').')')->implode(', ') }}{{ $items->count() > 3 ? ' +'.($items->count() - 3).' more' : '' }}</span>@endif</td>
      <td data-label="Requested by"><span class="small">{{ $requesters[$c->requested_by]->name ?? '—' }}</span></td>
      <td data-label=""><div class="btn-group">
        <button class="btn btn-xs btn-success" data-modal="catOk-{{ $c->id }}">Approve</button>
        <button class="btn btn-xs" data-modal="catMerge-{{ $c->id }}">Merge</button>
        <button class="btn btn-xs btn-ghost" data-modal="catNo-{{ $c->id }}">Reject</button>
      </div></td>
    </tr>
  @endforeach
  </tbody></table></div>
</div>
@foreach($pending as $c)
<div class="modal-backdrop hidden" id="catOk-{{ $c->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Approve "{{ $c->name }}"</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('admin.categories.review', $c) }}">@csrf<input type="hidden" name="action" value="approve"><div class="modal-body">
    <p class="small muted">It becomes a category in Browse, and the waiting items move into it. Fix the spelling if needed.</p>
    <div class="field"><label>Name</label><input class="input" name="name" required maxlength="60" value="{{ $c->name }}"></div>
    <div class="field"><label>Illustration</label><select class="input" name="icon">@foreach($icons as $i)<option @selected($c->icon === $i)>{{ $i }}</option>@endforeach</select></div>
    <div class="form-grid"><div class="field"><label>Gradient from</label><input class="input" type="color" name="grad_from" value="{{ $c->grad_from }}"></div><div class="field"><label>Gradient to</label><input class="input" type="color" name="grad_to" value="{{ $c->grad_to }}"></div></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Approve</button></div></form>
</div></div>
<div class="modal-backdrop hidden" id="catMerge-{{ $c->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Merge "{{ $c->name }}"</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('admin.categories.review', $c) }}">@csrf<input type="hidden" name="action" value="merge"><div class="modal-body">
    <div class="field"><label>Put its items under</label><select class="input" name="into" required>@foreach($list->where('active', true) as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Merge</button></div></form>
</div></div>
<div class="modal-backdrop hidden" id="catNo-{{ $c->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Reject "{{ $c->name }}"</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('admin.categories.review', $c) }}">@csrf<input type="hidden" name="action" value="reject"><div class="modal-body">
    <p class="small muted">Its items stay under Other Party Supplies. The supplier is told.</p>
    <div class="field"><label>Reason <span class="muted small">(optional)</span></label><input class="input" name="reason" maxlength="300" placeholder="e.g. Too specific; use Decorations"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Reject</button></div></form>
</div></div>
@endforeach
@endif

<div class="grid grid-3">
  @foreach($list as $c)
    <div class="card">
      <div class="row"><div class="thumb sm" style="background:{{ $c->gradient() }};display:grid;place-items:center">{{ art($c->icon, '60%') }}</div><div class="grow"><b>{{ $c->name }}</b><div class="small muted">{{ $c->products_count }} products · <code>{{ $c->id }}</code></div></div><span class="badge {{ $c->active ? 'b-green' : 'b-gray' }}">{{ $c->active ? 'Active' : 'Inactive' }}</span></div>
      <button class="btn btn-sm mt-2" data-modal="cat-{{ $c->id }}">{{ icon('edit') }}Edit</button>
    </div>
  @endforeach
</div>

@foreach(array_merge([null], $list->all()) as $c)
<div class="modal-backdrop hidden" id="{{ $c ? 'cat-'.$c->id : 'catNew' }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'New category' }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ $c ? route('admin.categories.update', $c) : route('admin.categories.store') }}">@csrf<div class="modal-body">
    <div class="field"><label>Name</label><input class="input" name="name" required maxlength="60" value="{{ $c->name ?? '' }}"></div>
    <div class="field"><label>Illustration</label><select class="input" name="icon">@foreach($icons as $i)<option @selected(($c->icon ?? 'gift') === $i)>{{ $i }}</option>@endforeach</select></div>
    <div class="form-grid"><div class="field"><label>Gradient from</label><input class="input" type="color" name="grad_from" value="{{ $c->grad_from ?? '#FBCFE8' }}"></div><div class="field"><label>Gradient to</label><input class="input" type="color" name="grad_to" value="{{ $c->grad_to ?? '#DB2777' }}"></div></div>
    <label class="check"><input type="checkbox" name="active" value="1" @checked($c->active ?? true)> Active</label>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
</div></div>
@endforeach
@endsection

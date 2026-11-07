@extends('layouts.dash', ['title' => 'Categories', 'nav' => 'categories'])

@section('content')
<div class="page-head"><div><h1>Categories</h1><p>Inactive categories disappear from the marketplace menus; their products stay listed under browse and search.</p></div><button class="btn btn-primary" data-modal="catNew">{{ icon('plus') }}New category</button></div>
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

@extends('layouts.dash', ['title' => 'Complaints & Issues', 'nav' => 'complaints'])

@section('content')
<div class="page-head"><div><h1>Complaints & issues</h1><p>Reports from customers. Every update is sent to the customer and logged.</p></div></div>
<details class="card mb-3" open>
  <summary class="strong" style="cursor:pointer">{{ icon('plus') }} Log an issue</summary>
  <form method="POST" action="{{ route('admin.complaints.store') }}" class="mt-2">@csrf
    <div class="grid grid-2">
      <div class="field"><label for="c-rep">Reported by</label><select class="input" id="c-rep" name="reporter_id" required>@foreach($customers as $u)<option value="{{ $u->id }}" @selected(old('reporter_id') == $u->id)>{{ $u->name }} · {{ $u->email }}</option>@endforeach</select></div>
      <div class="field"><label for="c-sup">About supplier (optional)</label><select class="input" id="c-sup" name="against_supplier_id"><option value="">—</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('against_supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
      <div class="field"><label for="c-req">Rental request code (optional)</label><input class="input" id="c-req" name="request_code" value="{{ old('request_code') }}" placeholder="PR-0001">@error('request_code')<div class="err">No request with that code.</div>@enderror</div>
      <div class="field"><label for="c-pri">Priority</label><select class="input" id="c-pri" name="priority">@foreach(['normal' => 'Normal', 'high' => 'High', 'low' => 'Low'] as $k => $l)<option value="{{ $k }}" @selected(old('priority') === $k)>{{ $l }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label for="c-sub">Subject</label><input class="input" id="c-sub" name="subject" value="{{ old('subject') }}" required maxlength="160"></div>
    <div class="field"><label for="c-body">Details</label><textarea class="input" id="c-body" name="body" required maxlength="2000">{{ old('body') }}</textarea></div>
    <button class="btn btn-primary">Log issue</button>
  </form>
</details>
<div class="tabs">@foreach(['open' => 'Open', 'in_review' => 'In review', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.complaints', ['tab' => $k]) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '🎉', 'title' => 'Nothing here', 'text' => 'No complaints in this tab.'])</div>
@else
<div class="stack">
  @foreach($list as $c)
    <div class="card">
      <div class="row wrap between top">
        <div><div class="row gap-sm"><b>{{ $c->code }}</b><span class="badge {{ ['open' => 'b-red', 'in_review' => 'b-gold', 'resolved' => 'b-green'][$c->status] }}">{{ ucfirst(str_replace('_', ' ', $c->status)) }}</span><span class="badge {{ ['high' => 'b-red', 'normal' => 'b-sky', 'low' => 'b-gray'][$c->priority] ?? 'b-gray' }} plain">{{ ucfirst($c->priority) }} priority</span></div>
          <h3 class="mt-1 mb-0">{{ $c->subject }}</h3>
          <div class="small muted">{{ $c->reporter->name }} · {{ fmt_datetime($c->created_at) }}{{ $c->againstSupplier ? ' · against '.$c->againstSupplier->name : '' }}{{ $c->rentalRequest ? ' · '.$c->rentalRequest->code : '' }}</div></div>
      </div>
      <p class="mt-1">{{ $c->body }}</p>
      @if($c->updates)<ul class="timeline">@foreach(array_reverse($c->updates) as $u)<li><span class="small muted">{{ fmt_datetime($u['at']) }} · {{ $staff[$u['by']] ?? 'Staff' }}</span><div class="small">{{ $u['note'] }}</div></li>@endforeach</ul>@endif
      <form method="POST" action="{{ route('admin.complaints.update', $c) }}" class="row wrap mt-2" style="gap:6px">@csrf
        <select class="input input-sm" name="status" style="width:auto">@foreach(['open' => 'Open', 'in_review' => 'In review', 'resolved' => 'Resolved'] as $k => $l)<option value="{{ $k }}" @selected($c->status === $k)>{{ $l }}</option>@endforeach</select>
        <input class="input input-sm grow" name="note" required maxlength="1000" placeholder="Update for the customer (required)">
        <button class="btn btn-sm btn-primary">Post update</button>
      </form>
    </div>
  @endforeach
</div>
@endif
@endsection

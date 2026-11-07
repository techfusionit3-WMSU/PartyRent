@extends('layouts.dash', ['title' => 'Complaints & Issues', 'nav' => 'complaints'])

@section('content')
<div class="page-head"><div><h1>Complaints & issues</h1><p>Reports from customers. Every update is sent to the customer and logged.</p></div></div>
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

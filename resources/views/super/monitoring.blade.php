@extends('layouts.dash', ['title' => 'Platform Monitoring', 'nav' => 'monitoring'])

@section('content')
<div class="page-head"><div><h1>Platform monitoring</h1><p>System health, work queues that need attention, and recent staff sign-ins.</p></div></div>
<div class="grid grid-2">
  <div class="card"><h3>System checks</h3>
    @foreach($checks as [$label, $ok, $detail])
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>{{ $label }}</b><div class="small muted">{{ $detail }}</div></div><span class="badge {{ $ok ? 'b-green' : 'b-gold' }}">{{ $ok ? 'OK' : 'Check' }}</span></div>
    @endforeach
  </div>
  <div class="card"><h3>Work queues</h3>
    @foreach($queues as [$label, $n, $href])
      <a class="row between" href="{{ $href }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><span>{{ $label }}</span><span class="badge {{ $n ? 'b-red' : 'b-green' }}">{{ $n }}</span></a>
    @endforeach
  </div>
  <div class="card"><h3>Records</h3><dl class="kv">@foreach($counts as $k => $v)<dt>{{ $k }}</dt><dd>{{ number_format($v) }}</dd>@endforeach</dl></div>
  <div class="card"><h3>Recent staff sign-ins</h3>
    @forelse($signins as $a)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span>{{ $a->actor->name ?? $a->details }} <span class="small muted">({{ $a->role }})</span></span><span class="small muted">{{ fmt_datetime($a->created_at) }}</span></div>@empty<p class="small muted">No staff sign-ins yet.</p>@endforelse
  </div>
</div>
@endsection

@extends('layouts.dash', ['title' => 'Activity Log', 'nav' => 'audit'])

@section('content')
<div class="page-head"><div><h1>Activity log</h1><p>Audit trail of sign-ins, approvals, payments, stock changes and settings. Entries can't be edited.</p></div></div>
<div class="card pad-0"><div class="table-wrap"><table class="table cards"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead><tbody>
  @foreach($list as $a)<tr><td data-label="When"><span class="small">{{ fmt_datetime($a->created_at) }}</span></td><td data-label="Who">{{ $a->actor->name ?? 'System' }}<div class="small muted">{{ $a->role }}</div></td><td data-label="Action"><b>{{ $a->action }}</b></td><td data-label="Details"><span class="small">{{ $a->details }}</span></td></tr>@endforeach
</tbody></table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div></div>
@endsection

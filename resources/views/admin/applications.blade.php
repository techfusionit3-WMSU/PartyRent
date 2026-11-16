@extends('layouts.dash', ['title' => 'Rental Partner Applications', 'nav' => 'applications'])

@section('content')
<div class="page-head"><div><h1>Rental Partner applications</h1><p>Approve shops before they can list items. Applicants keep their customer account either way.</p></div></div>
<div class="tabs">@foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'business' => 'Business checks'] as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.applications', ['tab' => $k]) }}">{{ $l }}<span class="count">{{ $counts[$k] ?? 0 }}</span></a>@endforeach</div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📄', 'title' => 'No applications here'])</div>
@else
  <div class="stack">
    @foreach($list as $s)
      <div class="card">
        <div class="row wrap between top">
          <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">{{ $s->logo }}</div><div><h3 class="mb-0">{{ $s->name }}</h3><div class="small muted">{{ $s->owner->name }} · {{ $s->owner->email }} · applied {{ fmt_datetime($s->applied_at) }}</div><p class="small mt-1 mb-0">{{ $s->description }}</p></div></div>
          <a class="btn btn-sm" href="{{ route('suppliers.show', $s) }}" target="_blank">Preview shop</a>
        </div>
        <dl class="kv mt-2"><dt>Account</dt><dd>@if($s->isBusiness())<b>Business</b> · {{ \App\Models\Supplier::REG_TYPES[$s->biz_reg_type] ?? $s->biz_reg_type }} {{ $s->biz_reg_no }} · TIN {{ $s->biz_tin }}@if($s->biz_doc_path) · <a href="{{ route('admin.business.doc', $s) }}" target="_blank" rel="noopener">View permit</a>@endif <span class="badge {{ $s->biz_status === 'verified' ? 'b-green' : ($s->biz_status === 'rejected' ? 'b-red' : 'b-gold') }}">{{ ['verified' => 'Verified', 'rejected' => 'Not accepted', 'pending' => 'To check'][$s->biz_status] ?? 'To check' }}</span>@else Personal (one-to-one) @endif</dd><dt>Location</dt><dd>{{ $s->address }}<div class="small muted">{{ \App\Models\Supplier::LOCATION_TYPES[$s->location_type][0] ?? $s->location_type }}@if($s->hasPin()) · <a href="https://www.openstreetmap.org/?mlat={{ $s->lat }}&mlon={{ $s->lng }}#map=17/{{ $s->lat }}/{{ $s->lng }}" target="_blank" rel="noopener">View pin on map</a>@else · no pin @endif</div></dd><dt>Contact</dt><dd>{{ $s->contact_person }} · {{ $s->phone }} · {{ $s->email }}</dd><dt>Fulfillment</dt><dd>{{ $s->fulfillmentLabel() }}{{ $s->offersDelivery() ? ' · '.$s->deliverySummary() : '' }}</dd>
          <dt>Payments</dt><dd>{{ collect($s->methods)->map(fn ($m) => \App\Models\Payment::METHOD_LABEL[$m] ?? $m)->implode(', ') }} · {{ collect(\App\Domain\Finance::plansOf($s))->map(fn ($a) => \App\Domain\Finance::PLAN_LABEL[$a])->implode(', ') }}</dd>
          <dt>Deposit</dt><dd>{{ $s->depositLabel() }}</dd>@if($s->reject_reason)<dt>Rejection reason</dt><dd style="color:var(--red)">{{ $s->reject_reason }}</dd>@endif</dl>
        @if($s->status === 'pending')
          <div class="row wrap mt-2" style="justify-content:flex-end;gap:8px">
            <form method="POST" action="{{ route('admin.applications.review', $s) }}" class="row" style="gap:6px">@csrf<input type="hidden" name="decision" value="reject"><input class="input input-sm" name="reason" required placeholder="Reason for rejection" style="width:260px"><button class="btn btn-danger btn-sm">Reject</button></form>
            <form method="POST" action="{{ route('admin.applications.review', $s) }}" data-confirm="Approve {{ $s->name }}? The shop and its listings become visible to customers." data-confirm-title="Approve rental partner?" data-confirm-ok="Approve">@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-success btn-sm">{{ icon('check') }}Approve</button></form>
          </div>
        @endif
        @if($tab === 'business')
          <div class="row wrap mt-2" style="justify-content:flex-end;gap:8px">
            <form method="POST" action="{{ route('admin.business.review', $s) }}" class="row" style="gap:6px">@csrf<input type="hidden" name="decision" value="reject"><input class="input input-sm" name="reason" required placeholder="What is wrong with the documents?" style="width:260px"><button class="btn btn-danger btn-sm">Not accepted</button></form>
            <form method="POST" action="{{ route('admin.business.review', $s) }}" data-confirm="Mark {{ $s->name }} as a Verified Business? They can then add staff accounts." data-confirm-title="Verify business?" data-confirm-ok="Verify">@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-success btn-sm">{{ icon('check') }}Verify business</button></form>
          </div>
        @endif
      </div>
    @endforeach
  </div>
@endif
@endsection

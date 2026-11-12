@extends('layouts.dash', ['title' => 'Shop Profile & Settings', 'nav' => 'shop'])

@section('content')
@php($owner = auth()->user()->shopRole() === 'owner')
<div class="page-head"><div><h1>Shop Profile & Settings</h1><p>What customers see on your shop page, and the payment rules applied to new requests.</p></div><a class="btn" href="{{ route('suppliers.show', $s) }}">{{ icon('eye') }}View public shop</a></div>

<div class="card mb-2" style="max-width:820px" id="account-type">
  <div class="card-head"><h3>Account type</h3>
    @if($s->isVerifiedBusiness())<span class="badge b-green">Verified Business</span>@elseif($s->isBusiness() && $s->biz_status === 'pending')<span class="badge b-gold">Business · documents being checked</span>@elseif($s->isBusiness() && $s->biz_status === 'rejected')<span class="badge b-red">Business · documents not accepted</span>@else<span class="badge b-gray">Personal</span>@endif
  </div>
  @if($s->isVerifiedBusiness())
    <p class="mb-0">{{ \App\Models\Supplier::REG_TYPES[$s->biz_reg_type] ?? '' }} {{ $s->biz_reg_no }} · TIN {{ $s->biz_tin }}.@if($owner) <a href="{{ route('supplier.staff') }}">Manage staff and roles →</a>@endif</p>
  @elseif($s->isBusiness() && $s->biz_status === 'pending')
    <p class="mb-0 small">PartyRent is checking your business documents. You can keep renting as usual; staff accounts unlock once you are verified.</p>
  @else
    @if($s->biz_status === 'rejected')<div class="alert alert-danger mb-2">{{ icon('alert') }}<div><b>We could not verify your documents:</b> {{ $s->biz_reject_reason }}. Fix the details below and send them again.</div></div>
    @else<p class="small">You rent one-to-one as a <b>Personal</b> account. A registered business can upgrade to add staff (manager, cashier, warehouse, coordinator) and get a "Verified Business" badge.</p>@endif
    @if($owner)
      <details @if($errors->hasAny(['biz_reg_type', 'biz_reg_no', 'biz_tin', 'biz_doc']) || $s->biz_status === 'rejected') open @endif><summary class="btn btn-sm">{{ $s->biz_status === 'rejected' ? 'Update business documents' : 'Upgrade to Business' }}</summary>
        <form method="POST" action="{{ route('supplier.business') }}" enctype="multipart/form-data" class="mt-2">@csrf
          @include('partials.business-fields', ['d' => $s->toArray(), 'choose' => false])
          <button class="btn btn-primary">Send for checking</button>
        </form>
      </details>
    @endif
  @endif
</div>

<form method="POST" action="{{ route('supplier.shop.update') }}" class="stack" style="max-width:820px">
  @csrf @method('PUT')
  <fieldset @disabled(! $owner) style="border:0;padding:0;margin:0;min-width:0"><div class="card shop-sections">@include('partials.shop-fields')</div></fieldset>
  @if($owner)<div class="row"><button class="btn btn-primary btn-lg">Save shop profile</button><span class="small muted">Submitted requests keep the terms that were quoted to the customer.</span></div>
  @else<p class="small muted">Only the shop owner can change these settings.</p>@endif
</form>
@push('head')<style>.shop-sections section + section{margin-top:28px;padding-top:20px;border-top:1px solid var(--line)}</style>@endpush
@endsection

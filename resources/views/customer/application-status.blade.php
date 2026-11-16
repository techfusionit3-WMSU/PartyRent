@extends('layouts.market', ['title' => 'Supplier application', 'nav' => 'profile'])

{{-- Reference statusView --}}
@php
    [$em, $cls, $txt] = [
        'pending' => ['🕒', 'b-gold', 'An administrator is verifying your information and documents. You will be notified once a decision is made. Meanwhile, you can keep renting as a customer.'],
        'approved' => ['🎉', 'b-green', 'Your shop is live. Switch to Supplier Mode to add products, packages and manage requests.'.($sp->type === 'organization' ? ' As Organization Admin you can add your cashier and staff under Team & Roles.' : '')],
        'rejected' => ['❌', 'b-red', 'Reason: '.($sp->reject_reason ?: '—').'. You may update your details and resubmit.'],
        'revision' => ['📝', 'b-purple', 'An administrator asked for changes: '.($sp->revision_note ?: '—').' Update your application and resubmit it.'],
        'suspended' => ['⛔', 'b-red', 'Your shop is suspended. Please contact PartyRent support.'],
    ][$sp->status] ?? ['🕒', 'b-gray', ''];
    $label = \App\Domain\Applications::STATUS[$sp->status][0] ?? ucfirst($sp->status);
@endphp
@section('content')
<div class="card center" style="max-width:640px;margin:20px auto;padding:34px 24px">
  <div style="font-size:3.2rem">{{ $em }}</div>
  <h1>Supplier application</h1>
  <div class="row wrap" style="justify-content:center;gap:10px"><span class="logo-tile" style="width:44px;height:44px;font-size:1.4rem;background:var(--brand-50)">{{ $sp->logo }}</span><b>{{ $sp->name }}</b><span class="badge {{ $cls }}">{{ $label }}</span><span class="badge b-gray plain">{{ \App\Domain\Applications::SUPPLIER_TYPES[$sp->type] ?? 'Supplier' }}</span></div>
  <p class="mt-2">{{ $txt }}</p>
  <p class="small muted">Submitted {{ fmt_datetime($sp->applied_at) }}{{ $sp->reviewed_at ? ' · Reviewed '.fmt_datetime($sp->reviewed_at) : '' }}</p>
  <div class="row wrap mt-2" style="justify-content:center">
    @if($sp->status === 'approved')<form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="btn btn-primary">{{ icon('swap') }}Switch to Supplier Mode</button></form>@endif
    @if(in_array($sp->status, ['rejected', 'revision'], true))<a class="btn btn-primary" href="{{ route('start-selling', ['edit' => 1]) }}">Update & resubmit</a>@endif
    @if($sp->status !== 'approved')<a class="btn" href="{{ route('suppliers.show', $sp) }}">Preview shop page</a>@endif
    <a class="btn btn-ghost" href="{{ route('home') }}">Back to marketplace</a>
  </div>
</div>
@endsection

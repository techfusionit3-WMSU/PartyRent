@extends('layouts.market', ['title' => 'Rental Partner application', 'nav' => 'profile'])

@php([$em, $lbl, $cls, $txt] = [
    'pending' => ['🕒', 'Pending Review', 'b-gold', 'An administrator is reviewing your application. You will be notified once it is approved. Meanwhile, you can keep renting as a customer.'],
    'approved' => ['🎉', 'Approved', 'b-green', 'Your shop is live. Switch to Rental Partner Mode to add products, packages and manage requests.'],
    'suspended' => ['⛔', 'Suspended', 'b-red', 'Your shop is suspended. Please contact PartyRent support.'],
][$sp->status] ?? ['❌', 'Rejected', 'b-red', 'Reason: '.($sp->reject_reason ?: '—').'. You may update your details and resubmit.'])
@section('content')
<div class="card center" style="max-width:640px;margin:20px auto;padding:34px 24px">
  <div style="font-size:3.2rem">{{ $em }}</div>
  <h1>Supplier application</h1>
  <div class="row" style="justify-content:center;gap:10px"><span class="logo-tile" style="width:44px;height:44px;font-size:1.4rem;background:var(--brand-50)">{{ $sp->logo }}</span><b>{{ $sp->name }}</b><span class="badge {{ $cls }}">{{ $lbl }}</span></div>
  <p class="mt-2">{{ $txt }}</p>
  <p class="small muted">Submitted {{ fmt_datetime($sp->applied_at) }}{{ $sp->reviewed_at ? ' · Reviewed '.fmt_datetime($sp->reviewed_at) : '' }}</p>
  <div class="row wrap mt-2" style="justify-content:center">
    @if($sp->status === 'approved')<form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="btn btn-primary">{{ icon('swap') }}Switch to Rental Partner Mode</button></form>@endif
    @if($sp->status === 'rejected')<a class="btn btn-primary" href="{{ route('start-selling', ['edit' => 1]) }}">Update & resubmit</a>@endif
    @if($sp->status !== 'approved')<a class="btn" href="{{ route('suppliers.show', $sp) }}">Preview shop page</a>@endif
    <a class="btn btn-ghost" href="{{ route('home') }}">Back to marketplace</a>
  </div>
</div>
@endsection

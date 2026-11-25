@extends('layouts.market', ['title' => 'Account Verification', 'nav' => 'profile'])

{{-- Customer verification before renting (App\Domain\Verifications). --}}
@section('content')
@php
  $badge = \App\Models\CustomerVerification::STATUS_BADGE[$v->status] ?? 'b-gray';
  $edit = $v->canSubmit();
  $file = fn ($w) => route('account.verification.file', $w);
@endphp
<div class="crumbs"><a href="{{ route('profile') }}">Profile</a> / Verification</div>
<div class="page-head"><div><h1>Account verification</h1><p>To keep rentals safe for shops and customers, PartyRent checks who you are before your first rental. Only PartyRent's review team sees your documents; shops only see the "verified" badges.</p></div></div>

<div class="card mb-3 row wrap between" style="gap:10px">
  <div>
    <div class="small muted strong" style="text-transform:uppercase;letter-spacing:.04em">Status</div>
    <div class="row wrap gap-sm"><span class="badge {{ $badge }}" style="font-size:.95rem">{{ $v->statusLabel() }}</span>
      @foreach($v->trustBadges() as [$l, $c])<span class="badge {{ $c }}">{{ $l }}</span>@endforeach</div>
    <div class="small mt-1">
      @switch($v->status)
        @case('approved') You are verified and can rent from any shop. @break
        @case('pending') Sent {{ $v->submitted_at?->diffForHumans() }}. PartyRent usually reviews it within 1 business day; you'll get a notification. @break
        @case('rejected') <b>Rejected:</b> {{ $v->reason }}. Fix it below and send it again. @break
        @case('resubmit') <b>Please send it again:</b> {{ $v->reason }} @break
        @default {{ $required ? 'Needed before you can check out.' : 'Optional for now, but shops trust verified customers more.' }}
      @endswitch
    </div>
  </div>
  @if($v->isApproved())<a class="btn btn-primary" href="{{ route('cart') }}">{{ icon('cart') }}Go to Rental List</a>@endif
</div>

@if($edit)
<form method="POST" action="{{ route('account.verification.submit') }}" enctype="multipart/form-data" data-no-enter class="grid grid-2 mb-3" style="align-items:start">@csrf
  <div class="card">
    <h3>1 · Government ID <span class="req">*</span></h3>
    <p class="small muted">A clear photo of a valid ID with your name and photo. Your full name should match your PartyRent account.</p>
    <div class="field"><label for="id_type">ID type</label><select class="input" id="id_type" name="id_type" required><option value="">Choose…</option>@foreach(\App\Models\CustomerVerification::ID_TYPES as $k => $l)<option value="{{ $k }}" @selected(old('id_type', $v->id_type) === $k)>{{ $l }}</option>@endforeach</select>@error('id_type')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label for="id_last4">Last 4 characters of the ID number</label><input class="input" id="id_last4" name="id_last4" maxlength="4" required value="{{ old('id_last4', $v->id_last4) }}" autocomplete="off" style="max-width:140px">@error('id_last4')<div class="error-text">{{ $message }}</div>@enderror<div class="small muted">Only the last 4 are kept, to match the photo.</div></div>
    <div class="form-grid">
      <div class="field"><label for="id_front">Front of the ID</label><input class="input" type="file" id="id_front" name="id_front" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-preview @unless($v->id_front_path) required @endunless>@if($v->id_front_path)<div class="small"><a href="{{ $file('id_front') }}" target="_blank" rel="noopener">View the one you sent</a> · choose a file to replace it</div>@endif @error('id_front')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label for="id_back">Back of the ID <span class="muted small">(if it has one)</span></label><input class="input" type="file" id="id_back" name="id_back" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-preview>@if($v->id_back_path)<div class="small"><a href="{{ $file('id_back') }}" target="_blank" rel="noopener">View the one you sent</a></div>@endif @error('id_back')<div class="error-text">{{ $message }}</div>@enderror</div>
    </div>
  </div>
  <div class="card">
    <h3>2 · Bank or e-wallet account <span class="req">*</span></h3>
    <p class="small muted">Proves the account you'll pay with is yours. <b>PartyRent never asks for your password, PIN or OTP</b>: don't send them to anyone.</p>
    <div class="field"><label for="pay_provider">Bank or e-wallet</label><select class="input" id="pay_provider" name="pay_provider" required><option value="">Choose…</option>@foreach(\App\Models\CustomerVerification::PAY_PROVIDERS as $p)<option @selected(old('pay_provider', $v->pay_provider) === $p)>{{ $p }}</option>@endforeach</select>@error('pay_provider')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="form-grid">
      <div class="field"><label for="pay_account_name">Account name</label><input class="input" id="pay_account_name" name="pay_account_name" required maxlength="120" value="{{ old('pay_account_name', $v->pay_account_name ?? auth()->user()->name) }}">@error('pay_account_name')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label for="pay_last4">Last 4 digits</label><input class="input" id="pay_last4" name="pay_last4" required maxlength="4" inputmode="numeric" value="{{ old('pay_last4', $v->pay_last4) }}" autocomplete="off">@error('pay_last4')<div class="error-text">{{ $message }}</div>@enderror<div class="small muted">of the account or mobile number</div></div>
    </div>
    <div class="field"><label for="pay_proof">Screenshot of the account</label><input class="input" type="file" id="pay_proof" name="pay_proof" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-preview @unless($v->pay_proof_path) required @endunless>
      <div class="small muted">Your app's profile page or a statement header showing your name and the last digits. Cover the balance if you like.</div>
      @if($v->pay_proof_path)<div class="small"><a href="{{ $file('pay_proof') }}" target="_blank" rel="noopener">View the one you sent</a></div>@endif @error('pay_proof')<div class="error-text">{{ $message }}</div>@enderror</div>
  </div>
  <div class="card">
    <h3>3 · Proof of insurance <span class="muted small">(optional)</span></h3>
    <p class="small muted">If you have event or liability insurance, add it: shops see an "Insurance" badge on your requests.</p>
    @include('customer._insurance-fields', ['v' => $v])
  </div>
  <div class="card">
    <h3>Send for review</h3>
    <label class="check small"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> I confirm these documents are mine and agree that PartyRent may review them to verify my account. They are not shared with shops.</label>
    @error('consent')<div class="error-text">{{ $message }}</div>@enderror
    <button class="btn btn-primary btn-block mt-2">{{ icon('shield') }}{{ $v->status === 'not_submitted' ? 'Send for review' : 'Send again' }}</button>
    <p class="small muted mt-1 mb-0">JPG, PNG, WEBP or PDF, up to 5 MB each.</p>
  </div>
</form>
@else
<div class="grid grid-2 mb-3" style="align-items:start">
  <div class="card">
    <h3>What you sent</h3>
    <div class="small"><b>ID:</b> {{ \App\Models\CustomerVerification::ID_TYPES[$v->id_type] ?? '—' }} · ends in {{ $v->id_last4 }}@if($v->id_front_path) · <a href="{{ $file('id_front') }}" target="_blank" rel="noopener">front</a>@endif @if($v->id_back_path) · <a href="{{ $file('id_back') }}" target="_blank" rel="noopener">back</a>@endif</div>
    <div class="small mt-1"><b>Payment account:</b> {{ $v->pay_provider }} · {{ $v->pay_account_name }} · •••• {{ $v->pay_last4 }}@if($v->pay_proof_path) · <a href="{{ $file('pay_proof') }}" target="_blank" rel="noopener">screenshot</a>@endif</div>
  </div>
  <div class="card">
    <h3>Proof of insurance <span class="muted small">(optional)</span></h3>
    @if($v->insurance_path)<p class="small">{{ ['submitted' => 'Sent, waiting for review.', 'verified' => 'Verified.', 'rejected' => 'Not accepted. You can send another.'][$v->insurance_status] ?? '' }} <a href="{{ $file('insurance') }}" target="_blank" rel="noopener">View</a></p>@endif
    <form method="POST" action="{{ route('account.verification.insurance') }}" enctype="multipart/form-data" data-no-enter>@csrf
      @include('customer._insurance-fields', ['v' => $v, 'required' => true])
      <button class="btn btn-sm mt-1">{{ $v->insurance_path ? 'Replace proof of insurance' : 'Send proof of insurance' }}</button>
    </form>
  </div>
</div>
@endif
@endsection

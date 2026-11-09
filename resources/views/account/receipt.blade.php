@extends('layouts.base', ['title' => 'Receipt '.$p->receipt_no])

@section('body')
<div style="max-width:560px;margin:24px auto;padding:0 16px">
  <div class="receipt printable card">
    <div class="r-head center"><div class="brand" style="justify-content:center"><span class="brand-mark">{{ icon('party') }}</span><span>Party<b>Rent</b></span></div><div class="small muted mt-1">Official Payment Receipt</div><h2 class="mt-1 mb-0">{{ $p->receipt_no }}</h2></div>
    <dl class="kv">
      <dt>Transaction ID</dt><dd>{{ $p->code }}</dd>
      <dt>Rental request</dt><dd>{{ $r->code }} · {{ $r->event_name }}</dd>
      <dt>Customer</dt><dd>{{ $r->customer->name }}</dd>
      <dt>Supplier</dt><dd>{{ $r->supplier->name }}</dd>
      <dt>Payment type</dt><dd>{{ $p->typeLabel() }}</dd>
      <dt>Method</dt><dd>{{ $p->methodLabel() }}</dd>
      <dt>Reference no.</dt><dd>{{ $p->reference ?: '—' }}</dd>
      <dt>Payment date</dt><dd>{{ fmt_date($p->date) }}</dd>
      <dt>Verified by</dt><dd>{{ $p->verifier->name ?? '—' }} · {{ fmt_datetime($p->verified_at) }}</dd>
    </dl>
    <div class="sum-line total"><span>Amount {{ $p->isOutflow() ? 'refunded' : 'received' }}</span><span>{{ money($p->amount) }}</span></div>
    @php($pr = $r->pricing ?? [])
    @if(($pr['salesSubtotal'] ?? 0) > 0 && in_array($p->type, \App\Domain\Finance::RENTAL_TYPES, true))
      @php($sp = \App\Domain\Finance::split($pr, (float) $p->amount))
      <div class="sum-line small"><span class="muted">Of this payment · rental income</span><span>{{ money($sp['rental']) }}</span></div>
      <div class="sum-line small"><span class="muted">Of this payment · sales income (consumables)</span><span>{{ money($sp['sales']) }}</span></div>
    @endif
    @if(isset($pr['rentalSubtotal']))
      <div class="sum-line"><span class="muted">Rental items</span><span>{{ money($pr['rentalSubtotal']) }}</span></div>
      @if($pr['salesSubtotal'] ?? 0)<div class="sum-line"><span class="muted">Items sold (not returned)</span><span>{{ money($pr['salesSubtotal']) }}</span></div>@endif
      @if($pr['servicesSubtotal'] ?? 0)<div class="sum-line"><span class="muted">Services</span><span>{{ money($pr['servicesSubtotal']) }}</span></div>@endif
      @if($pr['deliveryFee'] ?? 0)<div class="sum-line"><span class="muted">Delivery</span><span>{{ money($pr['deliveryFee']) }}</span></div>@endif
    @endif
    <div class="sum-line"><span class="muted">Order total</span><span>{{ money($f['payable']) }}</span></div>
    <div class="sum-line"><span class="muted">Total verified rental payments</span><span>{{ money($f['paidRental']) }}</span></div>
    <div class="sum-line"><span class="muted">Remaining balance (as of today)</span><span class="strong">{{ money($f['balance']) }}</span></div>
    @if($p->notes)<p class="small muted mt-1">{{ $p->notes }}</p>@endif
  </div>
  <div class="row mt-2" style="justify-content:center"><button class="btn btn-primary" onclick="window.print()">{{ icon('print') }}Print</button><button class="btn" onclick="history.length > 1 ? history.back() : window.close()">Close</button></div>
</div>
@endsection

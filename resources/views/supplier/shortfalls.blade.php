@extends('layouts.dash', ['title' => 'Problems & Cases', 'nav' => 'cases'])

{{-- Change list C12: report a shortfall (item, qty, dates, reason, photo) → affected bookings get a case each. --}}
@section('content')
<div class="page-head"><div><h1>Problems &amp; Cases</h1><p>Units damaged, lost or not back in time? Report them here. The units are taken out of stock on those dates, every booking that no longer fits gets a case, and its customer is told right away. Then agree a solution with each customer.</p></div>
  @if($products->isNotEmpty())<button class="btn btn-primary" data-modal="reportM">{{ icon('alert') }}Report a shortfall</button>@endif</div>
@if($errors->any())<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $errors->first() }}</div></div>@endif

<div class="card">
  @if($cases->isEmpty())
    @include('partials.empty', ['emoji' => '🛟', 'title' => 'No cases', 'text' => 'When you report a shortfall that affects bookings, the cases appear here.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Case</th><th>Booking</th><th>Customer</th><th>Item</th><th class="num">Units short</th><th>Status</th><th>Deadline</th><th></th></tr></thead><tbody>
      @foreach($cases as $c)
        @php($st = \App\Models\ShortfallCase::STATUS[$c->status] ?? [$c->status, 'b-gray'])
        <tr><td data-label="Case"><b>{{ $c->code }}</b></td><td data-label="Booking"><a href="{{ route('supplier.requests.show', $c->rentalRequest) }}">{{ $c->rentalRequest->code }}</a></td>
          <td data-label="Customer">{{ $c->customer->name }}</td><td data-label="Item">{{ $c->product->name }}</td><td data-label="Units short" class="num">{{ $c->units() }}</td>
          <td data-label="Status"><span class="badge {{ $st[1] }}">{{ $st[0] }}</span></td>
          <td data-label="Deadline">{{ $c->deadline_at ? $c->deadline_at->format('M j, g:i A') : '—' }}</td>
          <td class="right"><a class="btn btn-xs {{ in_array($c->status, ['reported', 'countered'], true) ? 'btn-primary' : '' }}" href="{{ route('cases.show', $c) }}">{{ in_array($c->status, ['reported', 'countered'], true) ? 'Respond' : 'Open' }}</a></td></tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>

<div class="modal-backdrop hidden" id="reportM"><div class="modal" role="dialog" aria-modal="true" aria-label="Report a shortfall"><div class="modal-head"><h3>Report a shortfall</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.shortfalls.store') }}" enctype="multipart/form-data">@csrf<div class="modal-body">
    <div class="field"><label for="srP">Item</label><select class="input" id="srP" name="product_id" required>@foreach($products as $p)<option value="{{ $p->id }}" @selected((int) old('product_id') === $p->id)>{{ $p->name }} ({{ $p->total_stock }} total)</option>@endforeach</select></div>
    <div class="field"><label for="srQ">Units you cannot provide</label><input class="input" type="number" id="srQ" name="qty" min="1" required value="{{ old('qty', 1) }}"></div>
    <div class="form-grid"><div class="field"><label for="srS">From</label><input class="input" type="date" id="srS" name="start_date" min="{{ today_iso() }}" required value="{{ old('start_date', today_iso()) }}"></div>
      <div class="field"><label for="srE">Until</label><input class="input" type="date" id="srE" name="end_date" min="{{ today_iso() }}" value="{{ old('end_date') }}"></div></div>
    <div class="field"><label for="srR">What happened?</label><textarea class="input" id="srR" name="reason" rows="3" required minlength="5" maxlength="1000" placeholder="e.g. 20 chairs were damaged in transport and cannot be replaced in time.">{{ old('reason') }}</textarea><div class="hint">The affected customers see this.</div></div>
    <div class="field"><label for="srF">Photo <span class="muted">(optional)</span></label><input class="input" type="file" id="srF" name="photo" accept="image/*"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Report</button></div></form>
</div></div>
@endsection

@extends('layouts.dash', ['title' => 'Installments & Overdue', 'nav' => 'balances'])

@section('content')
<div class="page-head"><div><h1>Installments & overdue accounts</h1><p>Active rentals with an outstanding balance. Overdue rentals are flagged and everyone involved is notified — rentals are never cancelled automatically.</p></div></div>
<div class="tabs">@foreach([['all', 'All with balance'], ['overdue', 'Overdue only']] as [$k, $l])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('cashier.balances', ['tab' => $k]) }}">{{ $l }}</a>@endforeach</div>
@php($rows = $tab === 'overdue' ? $rows->filter(fn ($r) => $r->fin()['isOverdue']) : $rows)
<div class="card pad-0">
  @if($rows->isEmpty())
    @include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing outstanding', 'text' => 'No active rentals have a balance in this view.'])
  @else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th>Arrangement</th><th>Next due</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach($rows as $r)
      @php($f = $r->fin())
      <tr class="{{ $f['isOverdue'] ? 'row-bad' : '' }}">
        <td data-label="Request"><b>{{ $r->code }}</b><div class="small muted">{{ $r->event_name }} · {{ fmt_date($r->start_date) }}</div></td>
        <td data-label="Customer">{{ $r->customer->name }}</td>
        <td data-label="Arrangement">{{ \App\Models\Payment::ARR_LABEL[$r->arrangement] }}</td>
        <td data-label="Next due">{{ $f['next'] ? $f['next']['label'] : '—' }}@if($f['next'])<div class="small {{ $f['next']['state'] === 'overdue' ? '' : 'muted' }}" style="{{ $f['next']['state'] === 'overdue' ? 'color:var(--red)' : '' }}">{{ fmt_date($f['next']['due']) }}</div>@endif</td>
        <td data-label="Paid" class="num">{{ money($f['paidRental']) }}</td>
        <td data-label="Balance" class="num"><b>{{ money($f['balance']) }}</b>@if($f['isOverdue'])<div class="small" style="color:var(--red)">{{ money($f['overdueAmount']) }} overdue</div>@endif</td>
        <td data-label="Status">@include('partials.badges', ['pay' => $f['status']])</td>
        <td><a class="btn btn-xs" href="{{ route('cashier.record', ['request' => $r->code]) }}">Record</a></td>
      </tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>
@endsection

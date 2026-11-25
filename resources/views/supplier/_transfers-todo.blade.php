{{-- Branch transfers waiting on the user's branches: to send, to receive, or to close after the rental (App\Domain\Transfers). --}}
@php
  $tdIds = \App\Support\BranchScope::viewIds();
  $tdSup = auth()->user()->shop();
  $todo = $tdSup ? \App\Models\RentalTransfer::where('supplier_id', $tdSup->id)->whereIn('status', \App\Models\RentalTransfer::OPEN)
      ->with(['rentalRequest:id,code,status,start_date', 'product:id,name', 'from:id,name', 'to:id,name'])->orderBy('due_on')->get()
      ->filter(fn ($t) => match ($t->status) {
          'planned' => $tdIds === null || in_array($t->from_location_id, $tdIds, true),
          'sent' => $tdIds === null || in_array($t->to_location_id, $tdIds, true),
          default => in_array($t->rentalRequest?->status, ['completed', 'cancelled', 'declined'], true) && ($tdIds === null || in_array($t->to_location_id, $tdIds, true)),
      })->values() : collect();
@endphp
@if($todo->isNotEmpty())
  <div class="card mb-3"><div class="card-head"><h3>{{ icon('truck') }} Stock to move between branches</h3><span class="badge b-gold">{{ $todo->count() }}</span></div>
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Rental</th><th>Item</th><th>From → to</th><th>To do</th></tr></thead><tbody>
      @foreach($todo->take(8) as $t)
        <tr><td data-label="Rental"><a href="{{ route('supplier.requests.show', $t->rental_request_id) }}">{{ $t->rentalRequest?->code }}</a></td>
          <td data-label="Item">{{ $t->qty }} × {{ $t->product?->name }}</td>
          <td data-label="From → to">{{ $t->from?->name }} → {{ $t->to?->name }}</td>
          <td data-label="To do">{{ ['planned' => 'Send by '.fmt_date($t->due_on?->toDateString()), 'sent' => 'Confirm received', 'received' => 'Send back or keep'][$t->status] }}@if($t->status === 'planned' && $t->due_on && $t->due_on->toDateString() < today_iso()) <span class="badge b-red">Late</span>@endif</td></tr>
      @endforeach
    </tbody></table></div>
  </div>
@endif

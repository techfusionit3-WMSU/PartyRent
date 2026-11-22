{{-- A rental's history: status steps, plus (for the shop and PartyRent) every action with who did it and when.
     $staff = true shows the staff actions (RecordStaffAction); customers see the status steps only. --}}
<ul class="timeline">
  @foreach($r->events()->with('user')->get()->reverse() as $h)
    @continue($h->status === 'activity' && empty($staff))
    <li>@if($h->status === 'activity')<b>{{ $h->note }}</b>@else<b>{{ \App\Models\RentalRequest::statusLabel($h->status) }}</b>@endif <span class="muted small">· {{ fmt_datetime($h->created_at) }} · {{ $h->user->name ?? 'System' }}</span>@if($h->note && $h->status !== 'activity')<div class="small">{{ $h->note }}</div>@endif</li>
  @endforeach
</ul>

<ul class="timeline">
  @foreach($r->events()->with('user')->get()->reverse() as $h)
    <li><b>{{ \App\Models\RentalRequest::statusLabel($h->status) }}</b> <span class="muted small">· {{ fmt_datetime($h->created_at) }} · {{ $h->user->name ?? 'System' }}</span>@if($h->note)<div class="small">{{ $h->note }}</div>@endif</li>
  @endforeach
</ul>

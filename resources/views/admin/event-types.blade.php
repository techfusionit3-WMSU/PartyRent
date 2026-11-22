@extends('layouts.dash', ['title' => 'Event Types', 'nav' => 'event-types'])

@section('content')
<div class="page-head"><div><h1>Event Types</h1><p>Which kinds of events each event company may offer, and how big. Check their proof, then verify with limits (you can set them lower than asked). A business can only list packages and take inquiries for verified types, within these limits.</p></div></div>
<div class="tabs">@foreach(['review' => 'To review', 'businesses' => 'Businesses', 'catalog' => 'Catalog'] as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.event-types', ['tab' => $k]) }}">{{ $l }}@if($counts[$k])<span class="count">{{ $counts[$k] }}</span>@endif</a>@endforeach</div>

@if($tab === 'review')
  @forelse($waiting as $r)
    @php($raise = $r->raise_pending && $r->status === 'verified')
    <div class="card mb-2">
      <div class="row between wrap top"><div><h3 class="mb-0">{{ $r->supplier->name }} · {{ $r->type->name }}</h3>
        <div class="small muted">{{ $raise ? 'Asks for a higher limit' : 'New application' }} · sent {{ fmt_datetime($r->updated_at) }}</div></div>
        <span class="badge {{ $raise ? 'b-gold' : $r->statusClass() }}">{{ $raise ? 'Higher limit' : $r->statusLabel() }}</span></div>
      <dl class="kv mt-2">
        <dt>Asks for</dt><dd><b>Up to {{ number_format($r->claimed_max_guests) }} guests · {{ $r->claimed_per_day }} a day</b>@if($raise)<div class="small muted">Now verified for {{ $r->limitsLabel() }}</div>@endif</dd>
        <dt>Done before</dt><dd>{{ $r->past_events }} {{ $r->type->name }} event{{ $r->past_events === 1 ? '' : 's' }}</dd>
        @if($r->type->guestsLabel())<dt>Usual for this type</dt><dd>{{ $r->type->guestsLabel() }}</dd>@endif
        @if($r->note)<dt>Their note</dt><dd>{{ $r->note }}</dd>@endif
        <dt>Photos</dt><dd>@if($r->photos)<div class="row wrap gap-sm">@foreach(array_values($r->photos) as $i => $ph)<a target="_blank" href="{{ route('admin.event-types.file', [$r, 'photo', $i]) }}"><img src="{{ route('admin.event-types.file', [$r, 'photo', $i]) }}" alt="Past event photo {{ $i + 1 }}" style="width:110px;height:80px;object-fit:cover;border-radius:8px"></a>@endforeach</div>@else<span class="muted">None</span>@endif</dd>
        @php($need = \App\Domain\EventTypes::proofsFor($r->type))
        @if($need)<dt>Documents</dt><dd>@foreach($need as $label)<div class="small">@if(! empty($r->docs[$label])){{ icon('check', 'width:14px;color:var(--green)') }} <a target="_blank" href="{{ route('admin.event-types.file', [$r, 'doc', array_search($label, array_keys($r->docs))]) }}">{{ $label }}</a>@else{{ icon('x', 'width:14px;color:var(--red)') }} {{ $label }}: <span class="muted">not given</span>@endif</div>@endforeach</dd>@endif
        <dt>Business</dt><dd>{{ $r->supplier->biz_status === 'verified' ? 'Verified Business' : 'Business documents: '.($r->supplier->biz_status ?: 'not checked') }} · {{ $r->supplier->city }} · {{ $r->supplier->eventBookings()->where('status', 'completed')->where('event_type', $r->type->name)->count() }} {{ $r->type->name }} event(s) done on PartyRent</dd>
      </dl>
      <form method="POST" action="{{ route('admin.event-types.review', $r) }}" class="row wrap mt-2" style="gap:8px;align-items:flex-end">@csrf
        <div class="field mb-0"><label for="mg-{{ $r->id }}">Verify up to (guests)</label><input class="input input-sm" type="number" min="1" max="{{ $r->claimed_max_guests }}" id="mg-{{ $r->id }}" name="max_guests" value="{{ $r->claimed_max_guests }}" style="width:130px"></div>
        <div class="field mb-0"><label for="pd-{{ $r->id }}">Events a day</label><input class="input input-sm" type="number" min="1" max="{{ $r->claimed_per_day }}" id="pd-{{ $r->id }}" name="per_day" value="{{ $r->claimed_per_day }}" style="width:100px"></div>
        <div class="field mb-0 grow"><label for="rs-{{ $r->id }}">Note / reason <span class="muted small">(needed to turn down)</span></label><input class="input input-sm" id="rs-{{ $r->id }}" name="reason" maxlength="500" placeholder="e.g. Photos show events up to 150 guests only"></div>
        <button class="btn btn-sm btn-success" name="decision" value="approve">{{ icon('check') }}Verify</button>
        <button class="btn btn-sm btn-danger" name="decision" value="reject">{{ icon('x') }}{{ $raise ? 'Keep current limit' : 'Turn down' }}</button>
      </form>
    </div>
  @empty
    <div class="card">@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing to review', 'text' => 'New applications and higher-limit requests from event companies show here.'])</div>
  @endforelse

@elseif($tab === 'businesses')
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Business</th><th>Event type</th><th>Status</th><th>Limits</th><th>Checked</th><th></th></tr></thead>
    <tbody>
    @forelse($all as $r)
      <tr><td data-label="Business"><b>{{ $r->supplier->name }}</b></td><td data-label="Event type">{{ $r->type->name }}</td>
        <td data-label="Status"><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>@if($r->reason && $r->status !== 'verified')<div class="small muted">{{ $r->reason }}</div>@endif</td>
        <td data-label="Limits" class="small">{{ $r->status === 'rejected' ? '—' : $r->limitsLabel() }}</td>
        <td data-label="Checked" class="small">{{ $r->reviewed_at ? fmt_date($r->reviewed_at) : '—' }}{{ $r->reviewer ? ' · '.$r->reviewer->name : '' }}</td>
        <td data-label="">@if(in_array($r->status, ['verified', 'paused'], true))
          <form method="POST" action="{{ route('admin.event-types.pause', $r) }}" class="row" style="gap:6px">@csrf<input type="hidden" name="pause" value="{{ $r->status === 'verified' ? 1 : 0 }}">
            @if($r->status === 'verified')<input class="input input-sm" name="reason" maxlength="500" placeholder="Why pause?" required style="width:160px">@endif
            <button class="btn btn-xs {{ $r->status === 'verified' ? 'btn-danger' : '' }}">{{ $r->status === 'verified' ? 'Pause' : 'Resume' }}</button></form>@endif</td></tr>
    @empty
      <tr><td colspan="6">@include('partials.empty', ['text' => 'No event company has applied yet.'])</td></tr>
    @endforelse
    </tbody>
  </table></div></div>

@else
  @php($canEdit = auth()->user()->hasPerm('categories.manage'))
  @foreach($types->where('status', 'requested') as $t)
    <div class="card mb-2" style="border-color:var(--gold)">
      <div class="row between wrap"><div><b>{{ $t->name }}</b> <span class="badge b-gold">Asked for</span><div class="small muted">by {{ $t->requester->name ?? 'a business' }}{{ $t->request_note ? ' · '.$t->request_note : '' }}</div></div>
      @if($canEdit)<div class="row" style="gap:6px"><form method="POST" action="{{ route('admin.event-types.decide', $t) }}">@csrf<input type="hidden" name="add" value="1"><button class="btn btn-sm btn-success">{{ icon('check') }}Add to list</button></form>
        <form method="POST" action="{{ route('admin.event-types.decide', $t) }}" class="row" style="gap:6px">@csrf<input type="hidden" name="add" value="0"><input class="input input-sm" name="reason" maxlength="500" placeholder="e.g. Use Birthday instead" style="width:200px"><button class="btn btn-sm">Don't add</button></form></div>@endif</div>
      <p class="small muted mb-0 mt-1">Before adding it, set its usual guests, parts and proof below (open it in the list).</p>
    </div>
  @endforeach
  <div class="stack">
  @foreach($types->where('status', '!=', 'requested')->concat($types->where('status', 'requested')) as $t)
    <details class="card et-card">
      <summary><span class="grow"><b>{{ $t->name }}</b> <span class="small muted">· {{ $t->guestsLabel() ?: 'no guest range' }} · {{ $t->verified_count }} business{{ $t->verified_count === 1 ? '' : 'es' }} verified</span></span>
        <span class="badge {{ ['active' => 'b-green', 'hidden' => 'b-gray', 'requested' => 'b-gold'][$t->status] }}">{{ ucfirst($t->status) }}</span></summary>
      <div class="et-body">@include('admin._event-type-form', ['t' => $t, 'canEdit' => $canEdit])</div>
    </details>
  @endforeach
  </div>
  @if($canEdit)<div class="card mt-3"><h3>Add an event type</h3>@include('admin._event-type-form', ['t' => null, 'canEdit' => true])</div>@endif
@endif
@endsection

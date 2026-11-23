@extends('layouts.dash', ['title' => 'Store History', 'nav' => 'activity'])

@php
  $T = \App\Models\AuditLog::TYPES;
  $C = \App\Models\AuditLog::CATEGORIES;
  $keep = array_filter(['range' => $rg['range'], 'from' => $rg['from'], 'to' => $rg['to'], 'who' => $who, 'type' => $type, 'q' => $q]);
  $dayName = function ($d) {
      $d = \Illuminate\Support\Carbon::parse($d);
      return $d->isToday() ? 'Today' : ($d->isYesterday() ? 'Yesterday' : $d->format('l, M j, Y'));
  };
@endphp

@section('content')
<div class="page-head no-print"><div><h1>Store History</h1><p>Everything that happened in your store: who did it, their role, when, and what changed. Records can't be edited or deleted.</p></div>
  <button type="button" class="btn btn-sm" onclick="window.print()">{{ icon('print') }}Print</button></div>

<div class="card no-print mb-2">
  @include('partials.range-picker', ['route' => 'supplier.activity'])
  <form method="GET" action="{{ route('supplier.activity') }}" class="hist-filters mt-1">
    @foreach(['range', 'from', 'to'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $rg[$k] }}">@endforeach
    @if($cat)<input type="hidden" name="cat" value="{{ $cat }}">@endif
    <input class="input input-sm" type="search" name="q" value="{{ $q }}" placeholder="Search: rental code, item, action…" aria-label="Search history">
    <select class="input input-sm" name="who" aria-label="Person"><option value="">Everyone</option>@foreach($people as $p)<option value="{{ $p->id }}" @selected($who === (string) $p->id)>{{ $p->name }}</option>@endforeach<option value="auto" @selected($who === 'auto')>PartyRent (automatic)</option></select>
    <select class="input input-sm" name="type" aria-label="Kind of action"><option value="">All actions</option>@foreach($T as $k => [$l])<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach</select>
    <button class="btn btn-sm btn-primary">Filter</button>
    @if($who || $type || $q || $cat)<a class="btn btn-sm btn-ghost" href="{{ route('supplier.activity', array_filter(['range' => $rg['range'], 'from' => $rg['from'], 'to' => $rg['to']])) }}">Clear</a>@endif
  </form>
  <div class="hist-cats mt-1" role="group" aria-label="Category">
    <a class="chip {{ $cat === '' ? 'active' : '' }}" href="{{ route('supplier.activity', $keep) }}">All <span class="muted">{{ $counts->sum() }}</span></a>
    @foreach($C as $k => [$l, $ic])@if($counts[$k] ?? 0)<a class="chip {{ $cat === $k ? 'active' : '' }}" href="{{ route('supplier.activity', $keep + ['cat' => $k]) }}">{{ icon($ic) }}{{ $l }} <span class="muted">{{ $counts[$k] }}</span></a>@endif @endforeach
  </div>
</div>

<div class="printable">
  <div class="print-only print-head"><b>{{ $sup->name }}</b> · Store History · {{ fmt_range($rg['from'], $rg['to']) }}<br><span class="small">Printed {{ fmt_datetime(now()) }} by {{ auth()->user()->name }}</span></div>
  @if($list->isEmpty())
    <div class="card">@include('partials.empty', ['emoji' => '🕘', 'title' => $q || $who || $type || $cat ? 'Nothing matches these filters' : 'Nothing recorded in this period', 'text' => 'Every change in your store shows here with who made it and when.'])</div>
  @else
  @php($rmap = \App\Models\RentalRequest::where('supplier_id', $sup->id)->whereIn('code', $list->pluck('ref')->filter()->unique())->get()->keyBy('code'))
  @foreach($list->groupBy(fn ($a) => $a->created_at->toDateString()) as $day => $rows)
    <div class="hist-day">{{ $dayName($day) }} <span class="muted">· {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('change', $rows->count()) }}</span></div>
    <div class="card hist-list">
      @foreach($rows as $a)
        <div class="hist-row">
          <div class="hist-ico" title="{{ $a->categoryLabel() }}">{{ icon($a->categoryIcon()) }}</div>
          <div class="hist-main">
            <div class="hist-what"><b>{{ $a->who() }}</b>@if($a->role_label && $a->actor)<span class="hist-role">{{ $a->role_label }}</span>@endif <span>{{ \Illuminate\Support\Str::lcfirst($a->action) }}</span>
              @if($a->ref)· @if($rr = $rmap[$a->ref] ?? null)<a href="{{ route('supplier.requests.show', $rr) }}" class="strong">{{ $a->ref }}</a>@else<span class="strong">{{ $a->ref }}</span>@endif @endif</div>
            <div class="hist-meta"><span class="badge {{ $a->typeBadge() }}">{{ $a->typeLabel() }}</span><span>{{ $a->categoryLabel() }}</span><time datetime="{{ $a->created_at->toIso8601String() }}" title="{{ $a->created_at->format('M j, Y g:i:s A') }}">{{ $a->created_at->format('g:i A') }}</time></div>
            @if($a->changes)
              <details class="hist-more"><summary>What changed</summary>
                @foreach($a->changes as $ch)
                  <div class="hist-rec"><b>{{ $ch['record'] }}{{ $ch['name'] !== '' ? ': '.$ch['name'] : '' }}</b> <span class="muted small">{{ ['created' => 'added', 'deleted' => 'removed', 'updated' => 'changed'][$ch['action']] ?? $ch['action'] }}</span></div>
                  @if($ch['fields'])
                    <table class="hist-diff"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
                    @foreach($ch['fields'] as $f)<tr><td>{{ $f['field'] }}</td><td class="old">{{ $f['old'] }}</td><td class="new">{{ $f['new'] }}</td></tr>@endforeach
                    </tbody></table>
                  @endif
                @endforeach
                <div class="small muted mt-1">{{ $a->created_at->format('l, M j, Y · g:i:s A') }}@if($a->details && $a->details !== $a->action) · {{ $a->details }}@endif</div>
              </details>
            @elseif($a->details && $a->details !== $a->action && ! str_ends_with($a->details, $a->action))
              <div class="small muted mt-1">{{ $a->details }}</div>
            @endif
          </div>
        </div>
      @endforeach
    </div>
  @endforeach
  <div class="mt-2 no-print">{{ $list->links('pagination::simple-default') }}</div>
  @endif
</div>
@endsection

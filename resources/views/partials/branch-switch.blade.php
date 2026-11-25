{{--
  Branch switcher (multi-branch shops). Organization-level users pick one branch or "All branches (whole organization)";
  staff limited to branches only see theirs, and with one branch it is locked. Every list page follows it
  (App\Support\BranchScope::viewIds). Needs $sup (the shop).
--}}
@php
  $bsUser = auth()->user();
  $bsChoices = $sup && ! $sup->isOrganization() ? \App\Support\BranchScope::choices($bsUser) : collect();
  $bsAll = $sup ? $sup->branches()->count() : 0;
  $bsCur = \App\Support\BranchScope::current($bsUser);
  $bsOrg = \App\Support\BranchScope::isOrgLevel($bsUser);
@endphp
@if($bsAll > 1 && $bsChoices->isNotEmpty())
  <div class="branch-switch {{ $bsCur ? 'on' : '' }}" role="region" aria-label="Branch">
    {{ icon('store') }}
    @if($bsChoices->count() === 1)
      <span>Your branch: <b>{{ $bsChoices->first()->name }}</b></span><span class="badge b-gray plain" title="You work at this branch only">Locked</span>
    @else
      <form method="POST" action="{{ route('branch-view') }}" class="row" style="gap:6px;flex-wrap:wrap">@csrf
        <label for="bsSel" class="small strong">Viewing</label>
        <select class="input input-sm" id="bsSel" name="branch" onchange="this.form.submit()" style="width:auto;max-width:240px">
          <option value="">{{ $bsOrg ? 'All branches (whole organization)' : 'All my branches' }}</option>
          @foreach($bsChoices as $b)<option value="{{ $b->id }}" @selected($bsCur?->id === $b->id)>{{ $b->name }}{{ $b->active ? '' : ' (closed)' }}</option>@endforeach
        </select>
        <noscript><button class="btn btn-xs">Show</button></noscript>
      </form>
      @if($bsCur)<span class="small muted">Lists, counts and reports show <b>{{ $bsCur->name }}</b> only.</span>@endif
    @endif
  </div>
@endif

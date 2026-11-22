{{-- Add / edit one event type in the catalog. $t EventType|null, $canEdit --}}
@php($id = $t?->id ?? 'new')
<form method="POST" action="{{ $t ? route('admin.event-types.update', $t) : route('admin.event-types.store') }}">@csrf
  <fieldset @disabled(! $canEdit) style="border:0;padding:0;margin:0">
  <div class="form-grid">
    <div class="field"><label for="et-n-{{ $id }}">Name</label><input class="input" id="et-n-{{ $id }}" name="name" required minlength="3" maxlength="60" value="{{ $t?->name }}" placeholder="e.g. Graduation"></div>
    <div class="field"><label>Usual guests</label><div class="row" style="gap:8px"><input class="input" type="number" min="1" name="min_guests" value="{{ $t?->min_guests }}" placeholder="min" aria-label="Minimum guests"><span class="muted">to</span><input class="input" type="number" min="1" name="max_guests" value="{{ $t?->max_guests }}" placeholder="max" aria-label="Maximum guests"></div></div>
  </div>
  <div class="field"><label for="et-d-{{ $id }}">Description</label><input class="input" id="et-d-{{ $id }}" name="description" maxlength="500" value="{{ $t?->description }}"></div>
  <div class="form-grid">
    <div class="field"><label for="et-p-{{ $id }}">Usual parts <span class="muted small">(one per line; add "(optional)")</span></label><textarea class="input" id="et-p-{{ $id }}" name="parts" rows="4" placeholder="Prenup shoot (optional)&#10;Ceremony&#10;Reception">{{ $t ? collect($t->parts)->map(fn ($p) => $p['name'].(! empty($p['optional']) ? ' (optional)' : ''))->implode("\n") : '' }}</textarea></div>
    <div class="field"><label for="et-s-{{ $id }}">Usual services <span class="muted small">(one per line)</span></label><textarea class="input" id="et-s-{{ $id }}" name="services" rows="4" placeholder="Catering&#10;Styling & décor">{{ $t ? implode("\n", (array) $t->services) : '' }}</textarea><div class="hint">With "Catering", businesses are also asked for a food handling permit.</div></div>
  </div>
  <div class="field"><label for="et-r-{{ $id }}">Documents a business must show <span class="muted small">(one per line)</span></label><textarea class="input" id="et-r-{{ $id }}" name="proofs" rows="2" placeholder="LGU event permit">{{ $t ? implode("\n", (array) $t->proofs) : '' }}</textarea></div>
  @if($t && $t->status !== 'requested')<label class="check mb-2"><input type="hidden" name="status" value="hidden"><input type="checkbox" name="status" value="active" @checked($t->status === 'active')> Open (businesses can apply, customers see it)</label>@endif
  @if($canEdit)<button class="btn btn-primary btn-sm">{{ icon('check') }}{{ $t ? 'Save' : 'Add event type' }}</button>@endif
  </fieldset>
</form>

{{-- Shortfall cases of a booking (change list C12). $r = RentalRequest --}}
@php($cases = \App\Models\ShortfallCase::where('rental_request_id', $r->id)->with('product')->orderBy('id')->get())
@foreach($cases as $sc)
  @php($st = \App\Models\ShortfallCase::STATUS[$sc->status] ?? [$sc->status, 'b-gray'])
  <div class="alert {{ $sc->status === 'resolved' ? 'alert-info' : 'alert-warn' }} mb-2">{{ icon('alert') }}<div class="grow"><b>Case {{ $sc->code }}</b> <span class="badge {{ $st[1] }}">{{ $st[0] }}</span> · {{ $sc->units() }} × {{ $sc->product->name }} cannot be provided on {{ collect(array_keys($sc->short))->map(fn ($d) => fmt_date($d))->implode(', ') }}.
    <a href="{{ route('cases.show', $sc) }}" class="strong">{{ $sc->status === 'resolved' ? 'See the resolution' : 'Open the case' }} →</a></div></div>
@endforeach

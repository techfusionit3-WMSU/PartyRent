@extends('layouts.market', ['title' => 'Where are you renting?'])

@section('content')
<div class="card" style="max-width:640px;margin:0 auto">
  <h1 class="mb-1" style="font-size:1.4rem">Where are you renting?</h1>
  <p class="muted">Pick the city or municipality of your event. Item pages then show what you can actually rent near you, and checkout picks the nearest shop branch that has everything.</p>
  @if($near)<div class="alert alert-info mb-2">{{ icon('map') }}<div>Now renting near <b>{{ $near['label'] }}</b>{{ ($near['from'] ?? '') === 'address' ? ' (from your account address)' : '' }}.</div></div>@endif
  <form method="POST" action="{{ route('near.store') }}" novalidate>@csrf
    <input type="hidden" name="back" value="{{ $back }}">
    @include('partials.address-fields', ['key' => 'near', 'value' => ['cityCode' => $near['city'] ?? null], 'full' => false, 'required' => true])
    @error('near.cityCode')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <div class="row wrap mt-2" style="gap:8px">
      <button class="btn btn-primary">{{ icon('check') }}Use this place</button>
      <a class="btn" href="{{ $back }}">Cancel</a>
    </div>
  </form>
  @if($near && ($near['from'] ?? '') === 'chosen')
    <form method="POST" action="{{ route('near.clear') }}" class="mt-2">@csrf @method('DELETE')<input type="hidden" name="back" value="{{ $back }}"><button class="link-btn small">Forget this place</button></form>
  @endif
</div>
@endsection

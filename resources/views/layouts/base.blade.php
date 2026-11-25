<!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'fil' ? 'fil' : 'en' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#E11D74">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="auth-id" content="{{ auth()->id() ?? 0 }}">
<meta name="auth-name" content="{{ auth()->user()?->name }}">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Ctext y=%22.9em%22 font-size=%2290%22%3E%F0%9F%8E%89%3C/text%3E%3C/svg%3E">
<title>{{ $title ?? 'Party Supplies Rental Marketplace' }} · {{ setting('platformName', 'PartyRent') }}</title>
<meta name="description" content="PartyRent — AI-assisted party supplies rental management system.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/base.css') }}?v={{ @filemtime(public_path('css/base.css')) }}">
<link rel="stylesheet" href="{{ asset('css/layout.css') }}?v={{ @filemtime(public_path('css/layout.css')) }}">
@php
    $iconSet = array_intersect_key((require app_path('Support/icons.php'))['icons'], array_flip(['check', 'alert', 'info', 'x', 'trash', 'plus', 'left', 'right', 'send']));
    $prRoutes = ['schedule' => route('api.schedule'), 'calendar' => route('api.calendar'), 'locations' => route('api.locations'), 'geoSearch' => route('api.geo.search'), 'geoReverse' => route('api.geo.reverse'), 'near' => route('near.store'),'chat' => route('ai.chat'), 'alternatives' => $alternativesUrl ?? null];
    $prMap = \Illuminate\Support\Arr::only(config('partyrent.map'), ['center', 'zoom', 'bounds', 'tiles', 'attribution']);
    $flash = [];
    foreach (['success', 'error', 'warn', 'info'] as $k) { if (session($k)) { $flash[] = [$k, session($k)]; } }
    if (isset($errors) && $errors->any()) { $flash[] = ['error', $errors->first()]; }
@endphp
<script>
window.PR = {
  routes: @json($prRoutes),
  currency: @json(currency_symbol()),
  map: @json($prMap),
  icons: @json($iconSet),
  flash: @json($flash)
};
</script>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
<script src="{{ asset('js/timefield.js') }}?v={{ @filemtime(public_path('js/timefield.js')) }}" defer></script>
<script src="{{ asset('js/charts.js') }}?v={{ @filemtime(public_path('js/charts.js')) }}" defer></script>
@stack('head')
</head>
<body @if(! empty($bodyClass)) class="{{ $bodyClass }}" @endif>
<a class="skip-link" href="#main">{{ __('Skip to content') }}</a>
@yield('body')
@stack('scripts')
</body>
</html>

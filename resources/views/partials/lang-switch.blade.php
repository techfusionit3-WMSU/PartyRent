{{-- English / Filipino switch (App\Http\Middleware\SetLocale). $dark = on a dark background. --}}
<form method="POST" action="{{ route('lang.switch') }}" class="lang-switch {{ ! empty($dark) ? 'dark' : '' }}" role="group" aria-label="{{ __('Language') }}">@csrf
  @foreach(\App\Http\Middleware\SetLocale::LOCALES as $code => $name)
    <button name="locale" value="{{ $code }}" lang="{{ $code }}" title="{{ $name }}" @if(app()->getLocale() === $code) aria-pressed="true" class="on" @else aria-pressed="false" @endif>{{ strtoupper($code === 'fil' ? 'Fil' : 'En') }}</button>
  @endforeach
</form>

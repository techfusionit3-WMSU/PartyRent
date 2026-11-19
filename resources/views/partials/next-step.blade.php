{{-- "What happens next": $ns = App\Domain\NextStep::rental(...) / ::event(...) --}}
@php($cls = ['you' => 'next-you', 'them' => 'next-them', 'done' => 'next-done'][$ns['turn']] ?? 'next-them')
<div class="next-step {{ $cls }}" role="status">
  <span class="next-ico" aria-hidden="true">{{ icon($ns['turn'] === 'you' ? 'right' : ($ns['turn'] === 'done' ? 'check' : 'clock')) }}</span>
  <div><div class="next-k">{{ $ns['turn'] === 'you' ? __('Your turn') : ($ns['turn'] === 'done' ? __('Status') : __('What happens next')) }}</div><div class="next-t">{{ $ns['text'] }}</div></div>
</div>

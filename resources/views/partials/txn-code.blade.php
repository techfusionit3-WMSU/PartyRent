{{-- Transaction code chip (same on the customer and supplier side): the request code both sides quote. --}}
<span class="txn-code" title="Quote this code when you contact the {{ ($side ?? 'customer') === 'supplier' ? 'customer' : 'shop' }} or PartyRent support">
  <span class="lbl">Transaction code</span><b>{{ $code }}</b>
  <button type="button" class="txn-copy" data-copy-code="{{ $code }}" aria-label="Copy transaction code {{ $code }}">{{ icon('copy') }}</button>
</span>

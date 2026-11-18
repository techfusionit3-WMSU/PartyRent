{{-- Customer <-> shop chat on a rental request. Needs $r, $chatSide (customer|supplier), $chat (rows + unread). --}}
@php
  $other = $chatSide === 'customer' ? $r->supplier->name : ($r->customer->name ?? 'the customer');
  $send = $chatSide === 'customer' ? route('rentals.messages.send', $r) : route('supplier.requests.messages.send', $r);
  $poll = $chatSide === 'customer' ? route('rentals.messages', $r) : route('supplier.requests.messages', $r);
@endphp
<div class="card chat-card" id="chat" data-chat data-poll="{{ $poll }}" data-last="{{ collect($chat['rows'])->last()['id'] ?? 0 }}">
  <div class="card-head"><h3>{{ icon('message') }} Messages with {{ $other }}</h3>@if($chat['unread'])<span class="badge b-pink">{{ $chat['unread'] }} new</span>@endif</div>
  <p class="small muted mt-0">Ask anything about this rental — no problem report needed. {{ $chatSide === 'customer' ? 'The shop' : 'The customer' }} is notified of new messages. Quote <b>{{ $r->code }}</b> if you call.</p>
  <div class="rq-thread" data-thread aria-live="polite">
    @forelse($chat['rows'] as $m)
      <div class="rq-msg {{ $m['mine'] ? 'mine' : 'theirs' }}"><div class="rq-meta">{{ $m['mine'] ? 'You' : $m['name'] }} · {{ $m['at'] }}</div><div class="rq-body">{{ $m['body'] }}</div></div>
    @empty
      <div class="rq-empty small muted" data-empty>No messages yet. Say hello to {{ $other }}.</div>
    @endforelse
  </div>
  <form method="POST" action="{{ $send }}" class="rq-form" data-no-leave-warn>@csrf
    <label for="chat-input" class="sr-only">Message to {{ $other }}</label>
    <textarea class="input" id="chat-input" name="body" rows="2" maxlength="{{ \App\Domain\Chat::MAX }}" required placeholder="Write a message to {{ $other }}…"></textarea>
    <button class="btn btn-primary">{{ icon('send') }}<span>Send</span></button>
  </form>
</div>

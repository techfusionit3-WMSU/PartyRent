<button class="chat-fab" aria-label="Open PartyRent Assist">{{ icon('message') }}</button>
<section class="chat-panel" aria-label="AI rental assistant">
  <div class="chat-head"><span class="avatar" style="background:rgba(255,255,255,.2)">{{ icon('sparkle', 'width:18px;height:18px') }}</span><div><div class="strong">PartyRent Assist</div><div class="small" style="opacity:.85">Answers from real listings only</div></div><button class="icon-btn" data-chat-close aria-label="Close">{{ icon('x') }}</button></div>
  <div class="chat-body" id="chatBody"></div>
  <div class="chat-sugg" id="chatSugg"></div>
  <form class="chat-input" id="chatForm"><input class="input" name="m" placeholder="Ask about prices, availability, payments…" autocomplete="off" aria-label="Message" maxlength="500"><button class="btn btn-primary btn-icon" aria-label="Send">{{ icon('send') }}</button></form>
</section>

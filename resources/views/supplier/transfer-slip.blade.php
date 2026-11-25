<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
  <title>Transfer slip · {{ $t->rentalRequest?->code }} · {{ $sup->name }}</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #eceff3; color: #111; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .bar { display: flex; gap: 8px; justify-content: center; padding: 10px; background: #111; }
    .bar button { font: inherit; padding: 6px 14px; border-radius: 8px; border: 0; cursor: pointer; }
    .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; }
    h1 { font-size: 1.2rem; margin: 0 0 2px; }
    .muted { color: #555; font-size: .85rem; }
    table { width: 100%; border-collapse: collapse; margin: 14px 0; }
    th, td { text-align: left; padding: 6px 8px; border: 1px solid #c9c9c9; vertical-align: top; }
    th { background: #f2f2f2; width: 32%; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 36px; }
    .sign div { border-top: 1px solid #111; padding-top: 4px; font-size: .85rem; }
    @page { size: A4; margin: 14mm 12mm; }
    @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; padding: 0; } }
    @media (max-width: 640px) { .sheet { margin: 0; padding: 16px; } .sign { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="bar"><button type="button" onclick="window.print()">Print</button><button type="button" onclick="history.length > 1 ? history.back() : window.close()">Close</button></div>
  <main class="sheet">
    <h1>{{ $sup->name }} · Branch transfer slip</h1>
    <div class="muted">For rental {{ $t->rentalRequest?->code }} · printed {{ fmt_datetime(now()) }} by {{ auth()->user()->name }}</div>
    <table>
      <tr><th>Item</th><td>{{ $t->product?->name }}</td></tr>
      <tr><th>Quantity</th><td><b>{{ $t->qty }}</b> {{ $t->product?->unitWord() }}{{ $t->qty === 1 ? '' : 's' }}</td></tr>
      <tr><th>From (lending branch)</th><td>{{ $t->from?->name }}<div class="muted">{{ $t->from?->address }}</div></td></tr>
      <tr><th>To (serving branch)</th><td>{{ $t->to?->name }}<div class="muted">{{ $t->to?->address }}</div></td></tr>
      <tr><th>Needed by</th><td>{{ fmt_date($t->due_on?->toDateString()) }} (rental {{ fmt_range($t->rentalRequest?->start_date, $t->rentalRequest?->end_date) }})</td></tr>
      <tr><th>Status</th><td>{{ $t->statusLabel() }}
        @if($t->sent_at)<div class="muted">Sent {{ fmt_datetime($t->sent_at) }} by {{ $t->sender?->name }}</div>@endif
        @if($t->received_at)<div class="muted">Received {{ fmt_datetime($t->received_at) }} by {{ $t->receiver?->name }}</div>@endif
        @if($t->closed_at)<div class="muted">{{ $t->status === 'kept' ? 'Kept at '.$t->to?->name : 'Sent back to '.$t->from?->name }} {{ fmt_datetime($t->closed_at) }} by {{ $t->closer?->name }}</div>@endif
      </td></tr>
    </table>
    <p class="muted">After the rental the items go back to {{ $t->from?->name }} unless the shop decides to keep them at {{ $t->to?->name }} (then the stock count moves too).</p>
    <div class="sign"><div>Released by (name, signature, date)</div><div>Received by (name, signature, date)</div></div>
  </main>
</body>
</html>

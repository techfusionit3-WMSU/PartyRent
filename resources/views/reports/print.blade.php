@php $wide = max(array_map(fn ($t) => count($t['cols']), $report['tables']) ?: [0]) > 8; @endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $report['title'] }} · {{ $head['period'] }} · {{ $head['name'] }}</title>
  <meta name="robots" content="noindex">
  @include('reports._styles')
  <style>
    :root { --ink: #111; --muted: #555; --line: #c9c9c9; --surface: #fff; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #eceff3; color: var(--ink); font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .sheet { background: #fff; max-width: {{ $wide ? '297mm' : '210mm' }}; margin: 16px auto; padding: 14mm 12mm; box-shadow: 0 2px 12px rgba(0,0,0,.12); }
    .bar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: center; padding: 10px 16px; background: #111; color: #fff; font-size: .88rem; }
    .bar button, .bar a { font: inherit; padding: 6px 14px; border-radius: 8px; border: 1px solid #fff; background: #fff; color: #111; cursor: pointer; text-decoration: none; }
    .bar a { background: transparent; color: #fff; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4px 6px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    thead th { background: #f2f2f2; font-size: .75rem; text-transform: uppercase; letter-spacing: .02em; }
    thead { display: table-header-group; } /* column headings repeat on every printed page */
    .sheet .rp-table th { white-space: normal; vertical-align: bottom; }
    .sheet .rp-table { font-size: 11px; }
    .sheet .rp-table th, .sheet .rp-table td { padding: 3px 4px; }
    tr, .rp-fig { break-inside: avoid; }
    .foot { margin-top: 14px; font-size: .75rem; color: var(--muted); display: flex; justify-content: space-between; }
    @media (max-width: 640px) { .sheet { margin: 0; padding: 16px; box-shadow: none; } }
    @page { size: A4 {{ $wide ? 'landscape' : 'portrait' }}; margin: 14mm 12mm 16mm; @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 9pt system-ui, sans-serif; color: #555; } @bottom-left { content: "{!! preg_replace('/["\\\\<>]/', '', $head['name'].' · '.$report['title']) !!}"; font: 9pt system-ui, sans-serif; color: #555; } }
    @media print {
      body { background: #fff; font-size: 10.5pt; }
      .bar { display: none; }
      .sheet { margin: 0; padding: 0; max-width: none; box-shadow: none; }
      .rp-wrap .rp-table thead { display: table-header-group !important; }
      .rp-wrap .rp-table tr { display: table-row !important; border: 0 !important; padding: 0 !important; }
      .rp-wrap .rp-table td { display: table-cell !important; padding: 3px 4px !important; border-bottom: 1px solid var(--line) !important; }
      .rp-wrap .rp-table td::before { content: none !important; }
      .rp-table { font-size: 9pt; }
    }
  </style>
</head>
<body>
  <div class="bar">
    @if($pdf)<span>To save a PDF, choose <b>Save as PDF</b> as the printer.</span>@endif
    <button type="button" onclick="window.print()">{{ $pdf ? 'Save as PDF' : 'Print' }}</button>
    <a href="{{ route($prefix.'center', $query) }}">Back to report</a>
  </div>
  <main class="sheet">
    @include('reports._body')
    <div class="foot"><span>{{ $head['name'] }} · {{ $report['title'] }} · {{ $head['period'] }}</span><span>Printed from {{ config('app.name', 'PartyRent') }}</span></div>
  </main>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>

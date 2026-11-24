{{-- Report styles shared by the screen view and the print page --}}
<style>
  .rp-head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; border-bottom: 2px solid var(--ink, #111); padding-bottom: 10px; margin-bottom: 10px; }
  .rp-name { font-size: 1.2rem; font-weight: 800; }
  .rp-line { font-size: .82rem; color: var(--muted, #555); }
  .rp-meta { text-align: right; font-size: .88rem; }
  .rp-title { margin: 0 0 4px; font-size: 1.15rem; }
  .rp-filters { font-size: .82rem; margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .rp-chip { border: 1px solid var(--line, #ccc); border-radius: 999px; padding: 1px 9px; }
  .rp-summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-bottom: 14px; }
  .rp-fig { border: 1px solid var(--line, #ccc); border-radius: 10px; padding: 8px 10px; background: var(--surface, #fff); }
  .rp-fig-k { font-size: .75rem; color: var(--muted, #555); font-weight: 600; }
  .rp-fig-v { font-size: 1.1rem; font-weight: 800; margin-top: 2px; }
  .rp-section { margin-bottom: 16px; }
  .rp-h { font-size: 1rem; margin: 0 0 6px; }
  .rp-count { font-size: .75rem; font-weight: 500; color: var(--muted, #555); }
  .rp-table { font-size: .82rem; }
  .rp-table th { white-space: nowrap; }
  .rp-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .rp-table tfoot td { font-weight: 800; border-top: 2px solid var(--ink, #111); }
  .rp-empty { padding: 14px; border: 1px dashed var(--line, #ccc); border-radius: 10px; text-align: center; color: var(--muted, #555); }
  .rp-note { font-size: .78rem; color: var(--muted, #555); margin: 4px 0 0; }
  @media (max-width: 640px) {
    .rp-meta { text-align: left; }
    .rp-wrap .rp-table thead { display: none; }
    .rp-wrap .rp-table tr { display: block; border: 1px solid var(--line, #ccc); border-radius: 10px; margin-bottom: 8px; padding: 4px 8px; }
    .rp-wrap .rp-table td { display: flex; justify-content: space-between; gap: 12px; border: 0; padding: 3px 0; text-align: right; }
    .rp-wrap .rp-table td::before { content: attr(data-label); font-weight: 600; color: var(--muted, #555); text-align: left; }
    .rp-wrap .rp-table td.num { white-space: normal; }
  }
</style>

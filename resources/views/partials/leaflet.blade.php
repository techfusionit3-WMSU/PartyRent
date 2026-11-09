{{-- Leaflet map library, loaded only on pages that show a map. Deferred after app.js, so it is ready before app.js boots on DOMContentLoaded. --}}
@once
@push('head')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<style>
.pr-map { height: 260px; border-radius: var(--radius-sm); border: 1px solid var(--line); overflow: hidden; z-index: 0; }
.pr-map.tall { height: 340px; }
</style>
@endpush
@endonce

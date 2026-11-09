@once
{{-- Full-screen viewer for review photos/videos; driven by [data-media-src] buttons in public/js/app.js --}}
<div class="modal-backdrop hidden lightbox" id="mediaLightbox" aria-hidden="true">
  <div class="lightbox-inner" role="dialog" aria-modal="true" aria-label="Customer photo or video">
    <button type="button" class="icon-btn lb-close" data-close aria-label="Close">{{ icon('x') }}</button>
    <button type="button" class="icon-btn lb-nav lb-prev" data-lb="-1" aria-label="Previous">{{ icon('left') }}</button>
    <div class="lb-stage"></div>
    <button type="button" class="icon-btn lb-nav lb-next" data-lb="1" aria-label="Next">{{ icon('right') }}</button>
    <div class="lb-count small"></div>
  </div>
</div>
@endonce

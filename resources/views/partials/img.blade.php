{{-- Listing image, or the category illustration on its gradient. $item = Product|Package --}}
@if(! empty($item->images))
  <img src="{{ asset('storage/'.$item->images[0]) }}" alt="{{ $item->name }}" loading="lazy">
@elseif($item instanceof \App\Models\Package)
  <div style="width:100%;height:100%;display:grid;place-items:center;background:linear-gradient(135deg,#F472B6,#8B5CF6)">{{ art('package', $artSize ?? '48%') }}</div>
@else
  @php($cat = $item->relationLoaded('category') ? $item->category : \App\Models\Category::find($item->category_id))
  <div style="width:100%;height:100%;display:grid;place-items:center;background:{{ $cat ? $cat->gradient() : 'linear-gradient(135deg,#FBCFE8,#DB2777)' }}">{{ art($cat->icon ?? 'gift', $artSize ?? '48%') }}</div>
@endif

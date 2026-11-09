<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Customer reviews: only customers whose rental is completed can review,
 * one review per rented item/package, with optional photos and a video.
 * Product and supplier ratings are always computed from visible reviews.
 */
class Reviews
{
    public const MAX_PHOTOS = 5;
    public const MAX_PHOTO_KB = 5120;   // 5 MB per photo
    public const MAX_VIDEO_KB = 20480;  // 20 MB video
    public const PHOTO_MIMES = 'jpg,jpeg,png,webp';
    public const VIDEO_MIMETYPES = 'video/mp4,video/webm,video/quicktime';
    public const MIN_COMMENT = 10;

    /**
     * The distinct items/packages of a rental that can be reviewed, with any existing review.
     *
     * @return array<string, array{key:string,type:string,id:int,name:string,review:?Review}>
     */
    public static function reviewables(RentalRequest $r): array
    {
        $existing = Review::where('rental_request_id', $r->id)->get();
        $out = [];
        foreach ($r->lines ?? [] as $l) {
            $type = $l['type'] === 'package' ? 'package' : 'item';
            $key = $type.'-'.$l['ref_id'];
            if (isset($out[$key])) {
                continue;
            }
            $model = $type === 'package' ? Package::find($l['ref_id']) : Product::find($l['ref_id']);
            if (! $model) {
                continue;
            }
            $out[$key] = [
                'key' => $key, 'type' => $type, 'id' => (int) $l['ref_id'], 'name' => $model->name,
                'review' => $existing->first(fn ($rv) => $type === 'package' ? $rv->package_id === (int) $l['ref_id'] : $rv->product_id === (int) $l['ref_id']),
            ];
        }

        return $out;
    }

    public static function canReview(RentalRequest $r, User $u): bool
    {
        return $r->status === 'completed' && $r->customer_id === $u->id;
    }

    /**
     * Save reviews for the items the customer rated.
     *
     * @param  array<string, array{rating?:int|string|null, comment?:string|null}>  $input  keyed by reviewable key
     * @param  array<string, array{photos?:UploadedFile[], video?:UploadedFile|null}>  $files
     * @return array{ok:bool, error?:string, errors?:array<string,string>, created?:int}
     */
    public static function submit(RentalRequest $r, User $u, array $input, array $files = []): array
    {
        if (! self::canReview($r, $u)) {
            return ['ok' => false, 'error' => 'You can review a rental only after it is completed.'];
        }
        $todo = array_filter(self::reviewables($r), fn ($x) => ! $x['review']);
        $errors = [];
        $rows = [];
        foreach ($todo as $key => $it) {
            $rating = (int) ($input[$key]['rating'] ?? 0);
            $comment = trim((string) ($input[$key]['comment'] ?? ''));
            $photos = array_values(array_filter($files[$key]['photos'] ?? []));
            $video = $files[$key]['video'] ?? null;
            if (! $rating) {
                if ($comment !== '' || $photos || $video) {
                    $errors["reviews.$key.rating"] = 'Choose a star rating for '.$it['name'].'.';
                }
                continue;
            }
            if (mb_strlen($comment) < self::MIN_COMMENT) {
                $errors["reviews.$key.comment"] = 'Tell other customers a bit more about '.$it['name'].' (at least '.self::MIN_COMMENT.' characters).';
                continue;
            }
            $rows[$key] = compact('it', 'rating', 'comment', 'photos', 'video');
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (! $rows) {
            return ['ok' => false, 'errors' => ['reviews' => 'Rate at least one item to submit a review.']];
        }

        $stored = [];
        try {
            DB::transaction(function () use ($rows, $r, $u, &$stored) {
                foreach ($rows as $row) {
                    $media = [];
                    foreach ($row['photos'] as $f) {
                        $stored[] = $p = $f->store('reviews/'.$r->id, 'public');
                        $media[] = ['type' => 'image', 'path' => $p, 'mime' => $f->getMimeType()];
                    }
                    if ($row['video']) {
                        $stored[] = $p = $row['video']->store('reviews/'.$r->id, 'public');
                        $media[] = ['type' => 'video', 'path' => $p, 'mime' => $row['video']->getMimeType()];
                    }
                    Review::create([
                        'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id, 'customer_id' => $u->id,
                        'product_id' => $row['it']['type'] === 'item' ? $row['it']['id'] : null,
                        'package_id' => $row['it']['type'] === 'package' ? $row['it']['id'] : null,
                        'rating' => $row['rating'], 'comment' => $row['comment'], 'media' => $media ?: null,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($stored);
            throw $e;
        }

        self::recalcSupplier($r->supplier_id);
        $n = count($rows);
        Notifier::notify($r->supplier->owner_id, 'supplier', 'New review on '.$r->code, $u->name.' left '.$n.' review'.($n > 1 ? 's' : '').' ('.implode(', ', array_map(fn ($x) => $x['rating'].'★ '.$x['it']['name'], $rows)).').', route('supplier.reviews', [], false));
        Notifier::audit('Posted review', $r->code.' · '.$n.' item(s)', $u);

        return ['ok' => true, 'created' => $n];
    }

    /** Recompute rating + count for a supplier and every product it owns, from visible reviews only. */
    public static function recalcSupplier(int $supplierId): void
    {
        $agg = fn ($q) => $q->selectRaw('COUNT(*) as n, AVG(rating) as avg')->first();
        foreach (Product::where('supplier_id', $supplierId)->get(['id']) as $p) {
            $a = $agg(Review::visible()->where('product_id', $p->id));
            Product::whereKey($p->id)->update(['rating' => $a->n ? round((float) $a->avg, 2) : 0, 'review_count' => (int) $a->n]);
        }
        $a = $agg(Review::visible()->where('supplier_id', $supplierId));
        Supplier::whereKey($supplierId)->update(['rating' => $a->n ? round((float) $a->avg, 2) : 0, 'review_count' => (int) $a->n]);
    }

    public static function recalcAll(): void
    {
        foreach (Supplier::pluck('id') as $id) {
            self::recalcSupplier((int) $id);
        }
        // Products whose supplier row is gone still get reset.
        Product::whereNotIn('supplier_id', Supplier::pluck('id'))->update(['rating' => 0, 'review_count' => 0]);
    }

    /** Average, count, star distribution and media count of a set of (visible) reviews. */
    public static function summary(Collection $reviews): array
    {
        $dist = [];
        foreach ([5, 4, 3, 2, 1] as $s) {
            $dist[$s] = $reviews->where('rating', $s)->count();
        }

        return [
            'count' => $reviews->count(),
            'avg' => $reviews->count() ? round($reviews->avg('rating'), 1) : 0,
            'dist' => $dist,
            'media' => $reviews->filter(fn ($r) => $r->hasMedia())->count(),
        ];
    }

    /** Filter values: all | media | 1..5 */
    public static function filter(Collection $reviews, ?string $f): Collection
    {
        return match (true) {
            $f === 'media' => $reviews->filter(fn ($r) => $r->hasMedia())->values(),
            in_array($f, ['1', '2', '3', '4', '5'], true) => $reviews->where('rating', (int) $f)->values(),
            default => $reviews,
        };
    }

    /** The shop replies once, publicly. */
    public static function reply(Review $rv, User $by, string $text): array
    {
        if (! $by->supplier || $by->supplier->id !== $rv->supplier_id) {
            return ['ok' => false, 'error' => 'Only the shop that was reviewed can reply.'];
        }
        if ($rv->reply) {
            return ['ok' => false, 'error' => 'You already replied to this review.'];
        }
        $rv->update(['reply' => trim($text), 'replied_at' => now()]);
        Notifier::notify($rv->customer_id, 'customer', $rv->supplier->name.' replied to your review', mb_strimwidth(trim($text), 0, 140, '…'), $rv->rental_request_id ? route('rentals.show', $rv->rental_request_id, false) : '');
        Notifier::audit('Replied to review', '#'.$rv->id, $by);

        return ['ok' => true];
    }

    public static function report(Review $rv, User $by, string $reason, ?string $details): array
    {
        if ($rv->customer_id === $by->id) {
            return ['ok' => false, 'error' => 'You cannot report your own review.'];
        }
        if (ReviewReport::where('review_id', $rv->id)->where('reporter_id', $by->id)->exists()) {
            return ['ok' => false, 'error' => 'You already reported this review. Our team will look into it.'];
        }
        ReviewReport::create(['review_id' => $rv->id, 'reporter_id' => $by->id, 'reason' => $reason, 'details' => $details ? trim($details) : null]);
        Notifier::notify('admin', 'admin', 'Review reported', $by->name.' reported a review on '.$rv->supplier->name.': '.(ReviewReport::REASONS[$reason] ?? $reason).'.', route('admin.reviews', [], false));

        return ['ok' => true];
    }

    /** action: hide | restore | dismiss */
    public static function moderate(Review $rv, User $by, string $action, ?string $reason): array
    {
        if ($action === 'hide') {
            if (! $reason) {
                return ['ok' => false, 'error' => 'Give a reason for hiding this review.'];
            }
            $rv->update(['hidden_at' => now(), 'hidden_reason' => $reason]);
            $rv->reports()->where('status', 'open')->update(['status' => 'actioned', 'resolved_by' => $by->id, 'resolved_at' => now()]);
            Notifier::notify($rv->customer_id, 'customer', 'Your review was hidden', 'A review you posted on '.$rv->supplier->name.' was hidden by PartyRent: '.$reason, '');
        } elseif ($action === 'restore') {
            $rv->update(['hidden_at' => null, 'hidden_reason' => null]);
        } else {
            $rv->reports()->where('status', 'open')->update(['status' => 'dismissed', 'resolved_by' => $by->id, 'resolved_at' => now()]);
        }
        self::recalcSupplier($rv->supplier_id);
        Notifier::audit(['hide' => 'Hid review', 'restore' => 'Restored review', 'dismiss' => 'Dismissed review reports'][$action] ?? $action, '#'.$rv->id.' · '.$rv->supplier->name.($reason ? ' — '.$reason : ''), $by);

        return ['ok' => true];
    }
}

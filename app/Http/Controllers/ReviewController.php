<?php

namespace App\Http\Controllers;

use App\Domain\Reviews;
use App\Models\Review;
use App\Models\ReviewReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supplier replies, customer reports and admin moderation of reviews. */
class ReviewController extends Controller
{
    /* ---------- supplier ---------- */

    public function supplierIndex(Request $request)
    {
        $sup = $request->user()->shop();
        $all = Review::where('supplier_id', $sup->id)->with(['customer', 'product', 'package'])->latest()->get();
        $tab = in_array($request->query('tab'), ['unreplied', 'all', 'hidden'], true) ? $request->query('tab') : 'unreplied';
        $visible = $all->whereNull('hidden_at');

        return view('supplier.reviews', [
            'sup' => $sup, 'tab' => $tab,
            'summary' => Reviews::summary($visible),
            'list' => match ($tab) {
                'hidden' => $all->whereNotNull('hidden_at')->values(),
                'all' => $visible->values(),
                default => $visible->whereNull('reply')->values(),
            },
            'counts' => ['unreplied' => $visible->whereNull('reply')->count(), 'all' => $visible->count(), 'hidden' => $all->whereNotNull('hidden_at')->count()],
        ]);
    }

    public function reply(Request $request, Review $review)
    {
        $data = $request->validate(['reply' => 'required|string|min:5|max:1000']);
        $res = Reviews::reply($review, $request->user(), $data['reply']);

        return $res['ok'] ? back()->with('success', 'Reply posted. The customer was notified.') : back()->with('error', $res['error']);
    }

    /* ---------- any signed-in user ---------- */

    public function report(Request $request, Review $review)
    {
        abort_if($review->hidden_at, 404);
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(ReviewReport::REASONS))], 'details' => 'nullable|string|max:500']);
        $res = Reviews::report($review, $request->user(), $data['reason'], $data['details'] ?? null);

        return $res['ok'] ? back()->with('success', 'Thanks — our team will review this report.') : back()->with('error', $res['error']);
    }

    /* ---------- admin ---------- */

    public function adminIndex(Request $request)
    {
        abort_unless($request->user()->hasPerm('complaints.manage'), 403, 'You do not have permission for this page.');
        $tab = in_array($request->query('tab'), ['reported', 'hidden', 'all'], true) ? $request->query('tab') : 'reported';
        $base = Review::with(['customer', 'product', 'package', 'supplier', 'reports.reporter'])->latest();
        $list = match ($tab) {
            'hidden' => (clone $base)->whereNotNull('hidden_at')->get(),
            'all' => (clone $base)->limit(200)->get(),
            default => (clone $base)->whereHas('reports', fn ($q) => $q->where('status', 'open'))->get(),
        };

        return view('admin.reviews', [
            'tab' => $tab, 'list' => $list,
            'counts' => [
                'reported' => Review::whereHas('reports', fn ($q) => $q->where('status', 'open'))->count(),
                'hidden' => Review::whereNotNull('hidden_at')->count(),
                'all' => Review::count(),
            ],
        ]);
    }

    public function moderate(Request $request, Review $review)
    {
        abort_unless($request->user()->hasPerm('complaints.manage'), 403);
        $data = $request->validate(['action' => 'required|in:hide,restore,dismiss', 'reason' => 'nullable|string|max:300|required_if:action,hide']);
        $res = Reviews::moderate($review, $request->user(), $data['action'], $data['reason'] ?? null);

        return $res['ok']
            ? back()->with('success', ['hide' => 'Review hidden and ratings recalculated.', 'restore' => 'Review restored and ratings recalculated.', 'dismiss' => 'Reports dismissed — the review stays visible.'][$data['action']])
            : back()->with('error', $res['error']);
    }
}

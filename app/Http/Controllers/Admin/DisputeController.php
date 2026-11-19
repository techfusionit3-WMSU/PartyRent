<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Damage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RentalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Damage charges a customer disputed (personal shops): compare the evidence and set the final amount. */
class DisputeController extends Controller
{
    private static function disputed()
    {
        return RentalRequest::where('status', 'inspection')->with(['customer', 'supplier'])->latest('updated_at')->get()
            ->filter(fn ($r) => ($r->inspection['review']['status'] ?? null) === 'disputed')->values();
    }

    public static function openCount(): int
    {
        return self::disputed()->count();
    }

    public function index()
    {
        $done = RentalRequest::whereNotNull('inspection')->with(['customer', 'supplier'])->latest('updated_at')->limit(200)->get()
            ->filter(fn ($r) => ! empty($r->inspection['review']['resolvedAt']))->take(20)->values();

        return view('admin.disputes', ['open' => self::disputed(), 'done' => $done]);
    }

    public function show(RentalRequest $rentalRequest)
    {
        abort_unless(isset($rentalRequest->inspection['review']), 404);

        return view('admin.dispute', ['r' => $rentalRequest->load(['customer', 'supplier'])]);
    }

    public function resolve(Request $request, RentalRequest $rentalRequest)
    {
        $data = $request->validate(['decisions' => 'required|array', 'decisions.*.amount' => 'required|numeric|min:0', 'decisions.*.note' => 'nullable|string|max:1000']);
        $res = Damage::resolveDispute($rentalRequest, $data['decisions'], $request->user()->id);

        return $res['ok']
            ? redirect()->route('admin.disputes')->with('success', $rentalRequest->code.': final compensation '.money($res['compensation']).'. Both sides were notified.')
            : back()->withInput()->with('error', $res['error']);
    }

    /** Private purchase receipt (admins in a dispute, and the shop that uploaded it). */
    public function receipt(Product $product)
    {
        abort_unless($product->purchase_receipt && Storage::disk('local')->exists($product->purchase_receipt), 404);

        return Storage::disk('local')->response($product->purchase_receipt);
    }
}

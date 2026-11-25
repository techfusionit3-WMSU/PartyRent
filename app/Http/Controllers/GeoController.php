<?php

namespace App\Http\Controllers;

use App\Domain\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Address search and reverse geocoding for the checkout delivery address and the browsing location (App\Domain\Geo). */
class GeoController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:120']);

        return response()->json(Geo::search($data['q']));
    }

    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        $hit = Geo::reverse((float) $data['lat'], (float) $data['lng']);

        return $hit ? response()->json($hit) : response()->json(['error' => 'That spot is outside the area PartyRent serves. Move the pin onto your location.'], 422);
    }
}

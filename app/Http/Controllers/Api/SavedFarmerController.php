<?php

// app/Http/Controllers/Api/SavedFarmerController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFarmerResource;
use App\Models\SavedFarmer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedFarmerController extends Controller
{
    /**
     * List the authenticated buyer's saved farmers.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (!$request->user()->isBuyer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only buyers can view saved farmers',
                ], 403);
            }

            $saved = SavedFarmer::where('buyer_id', $request->user()->id)
                ->with('farmer.farmerProfile')
                // Lets the frontend check whether one specific farmer is
                // already saved (e.g. to set a save button's initial state)
                // without fetching the whole list.
                ->when($request->farmer_id, function ($query, $farmerId) {
                    return $query->where('farmer_id', $farmerId);
                })
                ->latest()
                ->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => SavedFarmerResource::collection($saved),
                'meta' => [
                    'current_page' => $saved->currentPage(),
                    'last_page' => $saved->lastPage(),
                    'per_page' => $saved->perPage(),
                    'total' => $saved->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching saved farmers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching saved farmers',
            ], 500);
        }
    }

    /**
     * Save a farmer. Idempotent — saving an already-saved farmer returns
     * the existing record with 200 rather than an error.
     */
    public function store(Request $request, User $farmer): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->isBuyer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only buyers can save farmers',
                ], 403);
            }

            if ($farmer->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot save yourself',
                ], 422);
            }

            if (!$farmer->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only save farmer accounts',
                ], 422);
            }

            try {
                $saved = SavedFarmer::firstOrCreate([
                    'buyer_id' => $user->id,
                    'farmer_id' => $farmer->id,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // A concurrent request won the race between firstOrCreate's
                // own check and insert — fetch the row it just created.
                $saved = SavedFarmer::where('buyer_id', $user->id)
                    ->where('farmer_id', $farmer->id)
                    ->firstOrFail();
            }

            $saved->load('farmer.farmerProfile');

            return response()->json([
                'success' => true,
                'message' => $saved->wasRecentlyCreated ? 'Farmer saved successfully' : 'Farmer already saved',
                'data' => new SavedFarmerResource($saved),
            ], $saved->wasRecentlyCreated ? 201 : 200);
        } catch (\Exception $e) {
            \Log::error('Error saving farmer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving farmer',
            ], 500);
        }
    }

    /**
     * Unsave a farmer. Idempotent — removing a farmer that isn't currently
     * saved still succeeds, since the buyer's end goal ("this farmer is not
     * in my saved list") is already true either way.
     */
    public function destroy(Request $request, User $farmer): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->isBuyer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only buyers can remove saved farmers',
                ], 403);
            }

            SavedFarmer::where('buyer_id', $user->id)
                ->where('farmer_id', $farmer->id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Farmer removed from saved list',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error removing saved farmer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error removing saved farmer',
            ], 500);
        }
    }
}

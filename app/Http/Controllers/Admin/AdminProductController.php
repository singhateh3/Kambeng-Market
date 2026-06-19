<?php

// app/Http/Controllers/Admin/AdminProductController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminProductController extends Controller
{
    /**
     * Get all products with filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['farmer', 'orders'])
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->category, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->when($request->farmer_id, function ($query, $farmerId) {
                return $query->where('farmer_id', $farmerId);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('category', 'like', "%{$search}%")
                      ->orWhereHas('farmer', function ($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            })
            ->when($request->expiring_soon, function ($query) {
                return $query->where('status', 'active')
                    ->whereBetween('expiry_date', [now(), now()->addDays(7)]);
            })
            ->when($request->expired, function ($query) {
                return $query->where('expiry_date', '<', now());
            });

        $products = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get a specific product
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new ProductResource($product->load(['farmer', 'orders', 'reviews'])),
        ]);
    }

    /**
     * Delete a product
     */
    public function destroy(Product $product): JsonResponse
    {
        // Delete associated photos
        if ($product->photos) {
            foreach ($product->photos as $photo) {
                $path = str_replace('/storage/', '', $photo);
                if (\Storage::disk('public')->exists($path)) {
                    \Storage::disk('public')->delete($path);
                }
            }
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Bulk delete products
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        $products = Product::whereIn('id', $request->product_ids)->get();

        foreach ($products as $product) {
            if ($product->photos) {
                foreach ($product->photos as $photo) {
                    $path = str_replace('/storage/', '', $photo);
                    if (\Storage::disk('public')->exists($path)) {
                        \Storage::disk('public')->delete($path);
                    }
                }
            }
            $product->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $products->count() . ' products deleted successfully',
        ]);
    }
}
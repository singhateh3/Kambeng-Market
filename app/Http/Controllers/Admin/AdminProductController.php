<?php

// app/Http/Controllers/Admin/AdminProductController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class AdminProductController extends Controller
{
    /**
     * Get all products with filtering and search
     */
    public function index(Request $request): JsonResponse
    {
        try {
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
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas('farmer', function ($q) use ($search) {
                                $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('location', 'like', "%{$search}%");
                            });
                    });
                })
                ->when($request->expiring_soon, function ($query) {
                    return $query->where('status', 'active')
                        ->whereNotNull('expiry_date')
                        ->whereBetween('expiry_date', [now(), now()->addDays(7)]);
                })
                ->when($request->expired, function ($query) {
                    return $query->whereNotNull('expiry_date')
                        ->where('expiry_date', '<', now());
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
        } catch (\Exception $e) {
            \Log::error('Error fetching admin products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific product
     */
    public function show(Product $product): JsonResponse
    {
        try {
            $product->load(['farmer', 'orders', 'reviews']);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a product
     */
    public function destroy(Product $product): JsonResponse
    {
        try {
            // Delete associated photos from storage
            if ($product->photos) {
                foreach ($product->photos as $photo) {
                    try {
                        // Extract path from URL
                        $path = str_replace('/storage/', '', $photo);
                        $path = str_replace(asset('storage/'), '', $photo);

                        if (Storage::disk('public')->exists($path)) {
                            Storage::disk('public')->delete($path);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Could not delete photo: ' . $e->getMessage());
                    }
                }
            }

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk delete products
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'product_ids' => 'required|array',
                'product_ids.*' => 'exists:products,id',
            ]);

            $products = Product::whereIn('id', $request->product_ids)->get();
            $deletedCount = 0;

            foreach ($products as $product) {
                // Delete associated photos
                if ($product->photos) {
                    foreach ($product->photos as $photo) {
                        try {
                            $path = str_replace('/storage/', '', $photo);
                            $path = str_replace(asset('storage/'), '', $photo);

                            if (Storage::disk('public')->exists($path)) {
                                Storage::disk('public')->delete($path);
                            }
                        } catch (\Exception $e) {
                            \Log::warning('Could not delete photo: ' . $e->getMessage());
                        }
                    }
                }

                $product->delete();
                $deletedCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "{$deletedCount} products deleted successfully",
            ]);
        } catch (\Exception $e) {
            \Log::error('Error bulk deleting products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting products: ' . $e->getMessage(),
            ], 500);
        }
    }
}

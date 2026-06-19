<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductStatusRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Upload photos from the request and return stored paths/URLs.
     */
    private function uploadPhotos(Request $request): array
    {
        $photos = [];

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file && $file->isValid()) {
                    $path = $file->store('products', 'public');
                    $photos[] = Storage::url($path);
                }
            }
        }

        return $photos;
    }

    /**
     * Delete photos given an array of URLs or paths.
     */
    private function deletePhotos($photos): void
    {
        if (!is_array($photos)) {
            return;
        }

        foreach ($photos as $photo) {
            // If it's a full URL, convert to storage path
            $path = preg_replace('#^' . preg_quote(Storage::url(''), '#') . '#', '', $photo);
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * List all active products with filtering and pagination
     */
    public function index(ListProductsRequest $request): ProductCollection
    {
        $query = Product::with(['farmer' => function ($query) {
            $query->select('id', 'name', 'phone', 'location', 'avatar');
        }])
        ->withCount('orders')
        ->active()
        ->when($request->category, function ($query, $category) {
            // Support filtering by single category or array of categories
            if (is_array($category)) {
                return $query->whereIn('category', $category);
            }

            return $query->where('category', '=', $category);
        })
        ->when($request->region, function ($query, $region) {
            return $query->whereHas('farmer', function ($query) use ($region) {
                $query->where('location', 'like', "%{$region}%");
            });
        })
        ->when($request->search, function ($query, $search) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        });

        // Sorting
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->per_page ?? 20;
        $products = $query->paginate($perPage);

        return new ProductCollection($products);
    }

    /**
     * Store a newly created product
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Handle photo uploads
        $photoUrls = $this->uploadPhotos($request);

        $product = Product::create([
            'farmer_id' => $request->user()->id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'],
            'price' => $validated['price'],
            'harvest_date' => $validated['harvest_date'],
            'expiry_date' => $validated['expiry_date'],
            'photos' => $photoUrls,
        ]);

        return response()->json([
            'message' => 'Product listed successfully',
            'data' => new ProductResource($product->load('farmer')),
        ], 201);
    }

    /**
     * Display the specified product
     */
    public function show(Product $product): ProductResource
    {
        $product->load([
            'farmer' => function ($query) {
                $query->select('id', 'name', 'phone', 'location', 'avatar', 'email');
            },
            'farmer.farmerProfile',
            'orders' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        return new ProductResource($product);
    }

    /**
     * Update product status (active/sold)
     */
    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        $product->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Product status updated successfully',
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Remove the specified product
     */
    public function destroy(Product $product): JsonResponse
    {
        // Delete associated photos from storage
        if ($product->photos) {
            $this->deletePhotos($product->photos);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ], 200);
    }

    /**
     * Get products for the authenticated farmer
     */
    public function myProducts(Request $request): ProductCollection
    {
        $products = Product::where('farmer_id', $request->user()->id)
            ->with('farmer')
            ->withCount('orders')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return new ProductCollection($products);
    }

    /**
     * Get product categories for filtering
     */
    public function categories(): JsonResponse
    {
        $categories = Product::select('category')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        return response()->json(['data' => $categories]);
    }
}
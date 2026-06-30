<?php

// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductStatusRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected ?Cloudinary $cloudinary = null;

    public function __construct()
    {
        // Configure Cloudinary if available
        $cloudinaryUrl = env('CLOUDINARY_URL');
        if ($cloudinaryUrl) {
            try {
                $this->cloudinary = new Cloudinary($cloudinaryUrl);
            } catch (\Exception $e) {
                Log::warning('Cloudinary not configured: ' . $e->getMessage());
                $this->cloudinary = null;
            }
        }
    }

    /**
     * Upload photos to Cloudinary or use local storage fallback
     */
    private function uploadPhotos(Request $request): array
    {
        $photos = [];

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file && $file->isValid()) {
                    try {
                        if ($this->cloudinary) {
                            $result = $this->cloudinary->uploadApi()->upload(
                                $file->getRealPath(),
                                ['folder' => 'products']
                            );
                            $photos[] = $result['secure_url'];
                        } else {
                            $path = $file->store('products', 'public');
                            $photos[] = asset('storage/' . $path);
                        }
                    } catch (\Exception $e) {
                        Log::error('Upload error: ' . $e->getMessage());
                        $path = $file->store('products', 'public');
                        $photos[] = asset('storage/' . $path);
                    }
                }
            }
        }

        return $photos;
    }

    /**
     * Delete photos from Cloudinary given an array of URLs.
     */
    private function deletePhotos($photos): void
    {
        if (!is_array($photos) || empty($photos) || !$this->cloudinary) {
            return;
        }

        foreach ($photos as $url) {
            try {
                preg_match('/upload\/(?:v\d+\/)?(.+)\.[a-z]+$/i', $url, $matches);
                if (!empty($matches[1])) {
                    $this->cloudinary->uploadApi()->destroy($matches[1]);
                }
            } catch (\Exception $e) {
                Log::error('Cloudinary delete error: ' . $e->getMessage());
            }
        }
    }

    /**
     * List all active products with filtering and pagination
     */
    public function index(ListProductsRequest $request)
    {
        try {
            $query = Product::with(['farmer' => function ($query) {
                $query->select('id', 'name', 'phone', 'location', 'avatar');
            }])
                ->withCount('orders')
                ->active()
                ->when($request->category, function ($query, $category) {
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

            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->per_page ?? 20;
            $products = $query->paginate($perPage);

            return new ProductCollection($products);
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created product
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $photoUrls = $this->uploadPhotos($request);

            $product = Product::create([
                'farmer_id' => $request->user()->id,
                'name' => $validated['name'],
                'variety' => $validated['variety'] ?? null,
                'category' => $validated['category'],
                'quantity' => $validated['quantity'],
                'unit' => $validated['unit'],
                'price' => $validated['price'],
                'harvest_date' => $validated['harvest_date'],
                'expiry_date' => $validated['expiry_date'],
                'photos' => $photoUrls,
                'status' => 'active',
            ]);

            // Load farmer relationship
            $product->load('farmer');

            // Send notification to admins about new product
            try {
                $notificationService = app(NotificationService::class);

                // Get buyers who might be interested (you can customize this)
                $buyers = User::where('role', 'buyer')->get()->all();
                $notificationService->newProductListed($buyers, $product);

                Log::info('New product notification sent for product: ' . $product->id);
            } catch (\Exception $e) {
                Log::error('Error sending new product notification: ' . $e->getMessage());
                // Don't fail the product creation if notification fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Product listed successfully',
                'data' => new ProductResource($product->load('farmer')),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified product
     */
    public function show(Product $product)
    {
        try {
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
        } catch (\Exception $e) {
            Log::error('Error fetching product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }
    }

    /**
     * Update product status (active/sold)
     */
    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        try {
            $validated = $request->validated();
            $product->update(['status' => $validated['status']]);

            return response()->json([
                'success' => true,
                'message' => 'Product status updated successfully',
                'data' => new ProductResource($product),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating product status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating product status',
            ], 500);
        }
    }

    /**
     * Remove the specified product
     */
    public function destroy(Product $product): JsonResponse
    {
        try {
            if ($product->photos) {
                $this->deletePhotos($product->photos);
            }

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product',
            ], 500);
        }
    }

    /**
     * Get products for the authenticated farmer
     */
    public function myProducts(Request $request)
    {
        try {
            $products = Product::where('farmer_id', $request->user()->id)
                ->with('farmer')
                ->withCount('orders')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return new ProductCollection($products);
        } catch (\Exception $e) {
            Log::error('Error fetching farmer products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
            ], 500);
        }
    }

    /**
     * Get product categories for filtering
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = Product::where('status', 'active')
                ->whereNotNull('category')
                ->select('category')
                ->distinct()
                ->pluck('category')
                ->sort()
                ->values();

            if ($categories->isEmpty()) {
                $categories = collect([
                    'Vegetables',
                    'Fruits',
                    'Grains',
                    'Herbs',
                    'Spices',
                    'Dairy',
                    'Meat',
                    'Fish',
                    'Poultry',
                    'Eggs',
                    'Rice',
                    'Groundnuts',
                    'Cereals',
                    'Legumes',
                    'Roots',
                    'Tubers'
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching categories: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get featured products
     */
    public function featured()
    {
        try {
            $products = Product::with(['farmer' => function ($query) {
                $query->select('id', 'name', 'location');
            }])
                ->active()
                ->orderBy('created_at', 'desc')
                ->limit(8)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $products,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching featured products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching featured products',
                'data' => [],
            ], 200);
        }
    }
}

<?php

// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
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
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    protected ?Cloudinary $cloudinary = null;

    public function __construct()
    {
        // Configure Cloudinary if available.
        // Read via config(), not env() directly — production runs
        // `php artisan config:cache` on every boot, after which env()
        // calls outside config/*.php always return null, since Laravel
        // stops reading .env entirely once a config cache file exists.
        $cloudinaryUrl = config('services.cloudinary.url');
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
                            ->orWhere('category', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
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
     * Advanced search with filters, sorting, and pagination
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = Product::with(['farmer' => function ($q) {
                $q->select('id', 'name', 'location', 'avatar', 'phone');
            }])
                ->withAvg('reviews', 'rating')
                ->withCount('orders')
                ->active();

            // Search term - search in product name, category, description, and farmer name
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('category', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                        ->orWhereHas('farmer', function ($q) use ($searchTerm) {
                            $q->where('name', 'LIKE', "%{$searchTerm}%")
                                ->orWhere('location', 'LIKE', "%{$searchTerm}%");
                        });
                });
            }

            // Category filter
            if ($request->filled('category')) {
                $categories = is_array($request->category)
                    ? $request->category
                    : explode(',', $request->category);
                $query->whereIn('category', $categories);
            }

            // Price range filter
            if ($request->filled('min_price')) {
                $query->where('price', '>=', (float) $request->min_price);
            }
            if ($request->filled('max_price')) {
                $query->where('price', '<=', (float) $request->max_price);
            }

            // Location filter
            if ($request->filled('location')) {
                $query->whereHas('farmer', function ($q) use ($request) {
                    $q->where('location', 'LIKE', "%{$request->location}%");
                });
            }

            // Availability filter (in stock)
            if ($request->filled('in_stock')) {
                $query->where('quantity', '>', 0);
            }

            // Rating filter
            if ($request->filled('min_rating')) {
                $query->having('reviews_avg_rating', '>=', (float) $request->min_rating);
            }

            // Status filter (for farmer's own products)
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Sort options
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';

            $allowedSorts = ['name', 'price', 'created_at', 'quantity', 'rating', 'orders_count'];
            if (in_array($sortField, $allowedSorts)) {
                if ($sortField === 'rating') {
                    $query->orderBy('reviews_avg_rating', $sortOrder);
                } elseif ($sortField === 'orders_count') {
                    $query->orderBy('orders_count', $sortOrder);
                } else {
                    $query->orderBy($sortField, $sortOrder);
                }
            }

            // Pagination
            $perPage = $request->per_page ?? 20;
            $products = $query->paginate($perPage);

            // Get filter options for frontend
            $filterOptions = [
                'categories' => Product::select('category')
                    ->distinct()
                    ->whereNotNull('category')
                    ->where('status', 'active')
                    ->pluck('category')
                    ->filter()
                    ->values(),
                'min_price' => Product::where('status', 'active')->min('price') ?? 0,
                'max_price' => Product::where('status', 'active')->max('price') ?? 1000,
                'locations' => User::where('role', 'farmer')
                    ->whereNotNull('location')
                    ->distinct()
                    ->pluck('location')
                    ->filter()
                    ->values(),
            ];

            // Format products for response
            $formattedProducts = $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'price' => $product->price,
                    'price_formatted' => $product->price_formatted,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'photos' => $product->photos,
                    'status' => $product->status,
                    'description' => $product->description,
                    'avg_rating' => round($product->reviews_avg_rating ?? 0, 1),
                    'orders_count' => $product->orders_count,
                    'farmer' => $product->farmer ? [
                        'id' => $product->farmer->id,
                        'name' => $product->farmer->name,
                        'location' => $product->farmer->location,
                        'avatar' => $product->farmer->avatar,
                    ] : null,
                    'created_at' => $product->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedProducts,
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
                'filters' => $filterOptions,
            ]);
        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error performing search: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get autocomplete suggestions
     */
    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $searchTerm = $request->search;
            $type = $request->type ?? 'all';

            if (empty($searchTerm) || strlen($searchTerm) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $suggestions = [];

            // Search products
            if ($type === 'all' || $type === 'products') {
                $products = Product::where('status', 'active')
                    ->where(function ($q) use ($searchTerm) {
                        $q->where('name', 'LIKE', "%{$searchTerm}%")
                            ->orWhere('category', 'LIKE', "%{$searchTerm}%");
                    })
                    ->limit(5)
                    ->get(['id', 'name', 'category', 'price'])
                    ->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'label' => $product->name,
                            'type' => 'product',
                            'subtext' => $product->category . ' - ' . $product->price_formatted,
                            'link' => '/app/products/' . $product->id,
                            'icon' => '🌾',
                        ];
                    });
                $suggestions = array_merge($suggestions, $products->toArray());
            }

            // Search farmers
            if ($type === 'all' || $type === 'farmers') {
                $farmers = User::where('role', 'farmer')
                    ->where(function ($q) use ($searchTerm) {
                        $q->where('name', 'LIKE', "%{$searchTerm}%")
                            ->orWhere('location', 'LIKE', "%{$searchTerm}%");
                    })
                    ->limit(3)
                    ->get(['id', 'name', 'location'])
                    ->map(function ($farmer) {
                        return [
                            'id' => $farmer->id,
                            'label' => $farmer->name,
                            'type' => 'farmer',
                            'subtext' => $farmer->location ?? 'Farmer',
                            'link' => '/app/farmers/' . $farmer->id,
                            'icon' => '👨‍🌾',
                        ];
                    });
                $suggestions = array_merge($suggestions, $farmers->toArray());
            }

            // Search categories
            if ($type === 'all' || $type === 'categories') {
                $categories = Product::where('status', 'active')
                    ->where('category', 'LIKE', "%{$searchTerm}%")
                    ->distinct()
                    ->limit(3)
                    ->pluck('category')
                    ->map(function ($category) {
                        return [
                            'id' => $category,
                            'label' => $category,
                            'type' => 'category',
                            'subtext' => 'Category',
                            'link' => '/app/browse?category=' . urlencode($category),
                            'icon' => '📂',
                        ];
                    });
                $suggestions = array_merge($suggestions, $categories->toArray());
            }

            // Limit total suggestions
            $suggestions = array_slice($suggestions, 0, 10);

            return response()->json([
                'success' => true,
                'data' => $suggestions,
            ]);
        } catch (\Exception $e) {
            Log::error('Autocomplete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error getting suggestions: ' . $e->getMessage(),
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
                'description' => $validated['description'] ?? null,
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
                'reviews' => function ($query) {
                    // Qualify the column: reviews is loaded via a hasManyThrough
                    // join against orders, and both tables have a created_at
                    // column, which is ambiguous to the eager-load-with-limit
                    // subquery unless explicitly qualified.
                    $query->with('user')->latest('reviews.created_at')->limit(10);
                },
            ]);

            // Use loadCount() rather than pulling every order row just to
            // count them — orders_count is what ProductResource actually reads.
            $product->loadCount('orders');

            // Calculate average rating
            $product->avg_rating = round($product->reviews->avg('rating') ?? 0, 1);

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
     * Update the specified product
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        try {
            $validated = $request->validated();

            $product->update(collect($validated)->except(['photos', 'remove_photos'])->toArray());

            // Process photo removals before additions
            if (!empty($validated['remove_photos'])) {
                $this->deletePhotos($validated['remove_photos']);

                $remainingPhotos = array_values(array_diff($product->photos ?? [], $validated['remove_photos']));
                $product->update(['photos' => $remainingPhotos]);
            }

            if ($request->hasFile('photos')) {
                $newPhotos = $this->uploadPhotos($request);
                $product->update([
                    'photos' => array_merge($product->photos ?? [], $newPhotos),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => new ProductResource($product->fresh()->load('farmer')),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating product',
                'error' => $e->getMessage(),
            ], 500);
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

            // Check if low stock and send notification
            if ($product->quantity < 10 && $product->status === 'active') {
                try {
                    $notificationService = app(NotificationService::class);
                    $notificationService->lowStock($product->farmer, $product);
                } catch (\Exception $e) {
                    Log::error('Error sending low stock notification: ' . $e->getMessage());
                }
            }

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
     * Update product quantity
     */
    public function updateQuantity(Request $request, Product $product): JsonResponse
    {
        try {
            $user = $request->user();

            // Check if user owns this product
            if ($product->farmer_id !== $user->id && !$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this product',
                ], 403);
            }

            $request->validate([
                'quantity' => 'required|numeric|min:0',
                'is_available' => 'boolean',
            ]);

            $product->update([
                'quantity' => $request->quantity,
                'is_available' => $request->is_available ?? ($request->quantity > 0),
            ]);

            // Check if low stock and send notification
            if ($request->quantity < 10) {
                try {
                    $notificationService = app(NotificationService::class);
                    $notificationService->lowStock($user, $product);
                } catch (\Exception $e) {
                    Log::error('Error sending low stock notification: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Product quantity updated',
                'data' => $product,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating product quantity: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating product quantity: ' . $e->getMessage(),
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
     * Add photos to an existing product
     */
    public function addPhotos(Request $request, Product $product): JsonResponse
    {
        try {
            $request->validate([
                'photos' => 'required|array|max:10',
                'photos.*' => 'image|max:5120',
            ]);

            $newPhotos = $this->uploadPhotos($request);
            $existingPhotos = $product->photos ?? [];
            $product->update([
                'photos' => array_merge($existingPhotos, $newPhotos),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Photos added successfully',
                'data' => $product->photos,
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding photos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error adding photos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a specific photo from a product
     */
    public function deletePhoto(Request $request, Product $product): JsonResponse
    {
        try {
            $request->validate([
                'photo_url' => 'required|string',
            ]);

            $photos = $product->photos ?? [];
            $photoUrl = $request->photo_url;

            // Remove from array
            $photos = array_filter($photos, function ($photo) use ($photoUrl) {
                return $photo !== $photoUrl;
            });

            // Delete from Cloudinary
            $this->deletePhotos([$photoUrl]);

            $product->update(['photos' => array_values($photos)]);

            return response()->json([
                'success' => true,
                'message' => 'Photo deleted successfully',
                'data' => $product->photos,
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting photo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get products for the authenticated farmer
     */
    public function myProducts(Request $request)
    {
        try {
            $query = Product::where('farmer_id', $request->user()->id)
                ->with(['farmer' => function ($query) {
                    $query->select('id', 'name', 'phone', 'location', 'avatar');
                }])
                ->withCount('orders')
                ->withAvg('reviews', 'rating');

            // Search filter
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('category', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Category filter
            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }

            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Sort
            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            $products = $query->paginate($request->per_page ?? 20);

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

    /**
     * Get related products for a product
     */
    public function related(Product $product): JsonResponse
    {
        try {
            $related = Product::where('id', '!=', $product->id)
                ->where('category', $product->category)
                ->active()
                ->with(['farmer' => function ($query) {
                    $query->select('id', 'name', 'location');
                }])
                ->limit(6)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $related,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching related products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching related products',
            ], 500);
        }
    }
}

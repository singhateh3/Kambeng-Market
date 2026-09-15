<?php

// routes/api.php

use App\Http\Controllers\Admin\AdminDisputeController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPaymentTransactionController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFarmerVerificationController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FarmerProfileController;
use App\Http\Controllers\Api\ModemPayWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\FarmerVerificationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SavedFarmerController;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ============================================
// PUBLIC ROUTES (No authentication required)
// ============================================

// Auth — rate limiters defined in AppServiceProvider::configureRateLimiting()
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('/auth/google', [SocialAuthController::class, 'google'])->middleware('throttle:social-login');
Route::post('/auth/apple', [SocialAuthController::class, 'apple'])->middleware('throttle:social-login');
Route::get('/public/statistics', [PublicController::class, 'statistics'])->middleware('throttle:public-statistics');

// Public product routes (view only)
Route::middleware('throttle:public-products')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/categories', [ProductController::class, 'categories']);
    Route::get('/products/regions', [ProductController::class, 'regions']);
    Route::get('/products/featured', [ProductController::class, 'featured']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
});

// Create a simple keep-alive route
Route::get('/keep-alive', function () {
    return response()->json(['status' => 'alive', 'timestamp' => now()]);
});

// Public farmer profile routes
Route::get('/farmers/{userId}/profile', [FarmerProfileController::class, 'publicShow'])
    ->middleware('throttle:public-farmer-profile');

// ModemPay webhook — public, signature-verified instead of session-authenticated
Route::post('/webhooks/modempay', [ModemPayWebhookController::class, 'handle'])
    ->middleware('throttle:modempay-webhook');

// ============================================
// LOCAL-DEVELOPMENT-ONLY ROUTES
// ============================================
// Registered only when running locally — never present in production
// (Render sets APP_ENV=production, see render.yaml). Deliberately minimal
// even here: no database host/name, credentials, or table/schema listings.
//
// No local /migrate route: migrations already run automatically on every
// deploy via docker-entrypoint.sh, so an HTTP-triggered equivalent would
// be redundant as well as risky. No local /create-test-user route either
// — `php artisan tinker` covers that need without a standing route.
if (app()->environment('local')) {
    // Lightweight connectivity/environment check.
    Route::get('/debug', function () {
        $database = 'not connected';
        try {
            DB::connection()->getPdo();
            $database = 'connected';
        } catch (\Exception) {
            // Deliberately not surfacing the exception message — connection
            // exceptions often embed the host/port in the text.
        }

        return response()->json([
            'php_version' => phpversion(),
            'environment' => app()->environment(),
            'database' => $database,
        ]);
    });

    // Database connectivity check only — no schema/table details.
    Route::get('/db-test', function () {
        try {
            DB::connection()->getPdo();
            return response()->json([
                'success' => true,
                'message' => 'Database connected!',
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection failed.',
            ], 500);
        }
    });

    // Seed the local database.
    Route::get('/seed-database', function () {
        try {
            \Artisan::call('db:seed', ['--force' => true]);

            return response()->json([
                'success' => true,
                'message' => '✅ Database seeded successfully!',
                'output' => \Artisan::output()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    });
}
// ============================================
// ADMIN ROUTES (Authentication + Admin role required)
// ============================================
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    // Dashboard
    Route::get('/dashboard/statistics', [AdminDashboardController::class, 'statistics']);
    Route::get('/dashboard/charts', [AdminDashboardController::class, 'chartData']);

    // User Management
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('/{user}', [AdminUserController::class, 'show']);
        Route::put('/{user}/role', [AdminUserController::class, 'updateRole'])->middleware('throttle:admin-write');
        Route::post('/{user}/verify', [AdminUserController::class, 'verifyFarmer'])->middleware('throttle:admin-write');
        Route::patch('/{user}/toggle-status', [AdminUserController::class, 'toggleStatus'])->middleware('throttle:admin-write');
        Route::delete('/{user}', [AdminUserController::class, 'destroy'])->middleware('throttle:admin-write');
    });

    // Profile routes
    Route::get('/user', [ProfileController::class, 'show']);
    Route::post('/user/profile', [ProfileController::class, 'update'])->middleware('throttle:admin-write');
    Route::put('/user/profile', [ProfileController::class, 'update'])->middleware('throttle:admin-write');

    // Farmer Verification Routes
    Route::prefix('farmers')->group(function () {
        Route::get('/', [AdminFarmerVerificationController::class, 'index']);
        Route::get('/verification/pending', [AdminFarmerVerificationController::class, 'pending']);
        Route::get('/verification/statistics', [AdminFarmerVerificationController::class, 'statistics']);
        Route::get('/verification/{farmer}', [AdminFarmerVerificationController::class, 'show']);
        Route::post('/verification/{farmer}/approve', [AdminFarmerVerificationController::class, 'approve'])->middleware('throttle:admin-write');
        Route::post('/verification/{farmer}/reject', [AdminFarmerVerificationController::class, 'reject'])->middleware('throttle:admin-write');
        Route::post('/verification/bulk-approve', [AdminFarmerVerificationController::class, 'bulkApprove'])->middleware('throttle:admin-write');
        Route::post('/verification/{farmer}/upload-document', [AdminFarmerVerificationController::class, 'uploadDocument'])->middleware('throttle:admin-write');
    });

    // Product Management
    Route::prefix('products')->group(function () {
        Route::get('/', [AdminProductController::class, 'index']);
        Route::get('/{product}', [AdminProductController::class, 'show']);
        Route::delete('/{product}', [AdminProductController::class, 'destroy'])->middleware('throttle:admin-write');
        Route::post('/bulk-delete', [AdminProductController::class, 'bulkDelete'])->middleware('throttle:admin-write');
    });

    // Order Management
    Route::prefix('orders')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus'])->middleware('throttle:admin-write');
        // Financial actions get their own, tighter, dedicated limiter
        // instead of the general admin-write one — see AppServiceProvider.
        Route::post('/{order}/confirm-refund', [AdminOrderController::class, 'confirmRefund'])->middleware('throttle:admin-financial');
        Route::post('/{order}/retry-payout', [AdminOrderController::class, 'retryPayout'])->middleware('throttle:admin-financial');
        Route::delete('/{order}', [AdminOrderController::class, 'destroy'])->middleware('throttle:admin-write');
    });

    // Payment ledger (read-only)
    Route::get('/payment-transactions', [AdminPaymentTransactionController::class, 'index']);

    // Dispute Management
    Route::prefix('disputes')->group(function () {
        Route::get('/', [AdminDisputeController::class, 'index']);
        Route::get('/{dispute}', [AdminDisputeController::class, 'show']);
        Route::patch('/{dispute}/status', [AdminDisputeController::class, 'updateStatus'])->middleware('throttle:admin-write');
    });
});

// ============================================
// PROTECTED ROUTES (Authentication required)
// ============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:profile-update');
    Route::post('/user/refresh-token', [AuthController::class, 'refreshToken']);

    // Farmer Profile routes
    Route::prefix('farmer')->group(function () {
        Route::get('/profile', [FarmerProfileController::class, 'show']);
        Route::put('/profile', [FarmerProfileController::class, 'update'])->middleware('throttle:profile-update');
        Route::post('/profile/verify', [FarmerProfileController::class, 'submitVerification']);
        Route::post('/profile/avatar', [FarmerProfileController::class, 'uploadAvatar'])->middleware('throttle:avatar-upload');
        Route::get('/profile/statistics', [FarmerProfileController::class, 'statistics']);
    });

    // Farmer verification requests
    Route::post('/farmer/request-verification', [FarmerVerificationController::class, 'requestVerification']);
    Route::get('/farmer/verification-status', [FarmerVerificationController::class, 'status']);

    // Product routes (authenticated users)
    // IMPORTANT: Specific routes MUST come BEFORE wildcard routes
    Route::get('/my-products', [ProductController::class, 'myProducts']); // <-- This must be BEFORE /products/{product}

    Route::prefix('products')->group(function () {
        Route::post('/', [ProductController::class, 'store'])->middleware('throttle:products-create');
        Route::put('/{product}', [ProductController::class, 'update'])->middleware('throttle:products-write');
        Route::patch('/{product}/status', [ProductController::class, 'updateStatus'])->middleware('throttle:products-write');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->middleware('throttle:products-write');
        Route::delete('/{product}/photo', [ProductController::class, 'deletePhoto'])->middleware('throttle:products-write');
        Route::post('/{product}/photos', [ProductController::class, 'addPhotos'])->middleware('throttle:products-write');
    });

    // Order routes (authenticated users)
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store'])->middleware('throttle:orders-create');
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::patch('/{order}/status', [OrderController::class, 'updateStatus'])->middleware('throttle:order-actions');
        Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:order-actions');
        Route::post('/{order}/review', [OrderController::class, 'review'])->middleware('throttle:review-create');
        Route::post('/{order}/report', [OrderController::class, 'report'])->middleware('throttle:order-actions');
        Route::post('/{order}/confirm', [OrderController::class, 'confirm'])->middleware('throttle:order-actions');
    });

    // Saved Farmers (buyer only — enforced in SavedFarmerController)
    Route::prefix('saved-farmers')->group(function () {
        Route::get('/', [SavedFarmerController::class, 'index']);
        Route::post('/{farmer}', [SavedFarmerController::class, 'store'])->middleware('throttle:saved-farmers-write');
        Route::delete('/{farmer}', [SavedFarmerController::class, 'destroy'])->middleware('throttle:saved-farmers-write');
    });

    // Notification routes - updated to match frontend
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/{notification}/read', [NotificationController::class, 'markAsRead'])->middleware('throttle:notifications-write');
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead'])->middleware('throttle:notifications-write');
        Route::delete('/read', [NotificationController::class, 'deleteRead'])->middleware('throttle:notifications-write');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->middleware('throttle:notifications-write');
    });
});

// Route::get('/debug-product/{id}', function ($id) {
//     $product = \App\Models\Product::with(['farmer'])->find($id);

//     if (!$product) {
//         return response()->json([
//             'exists' => false,
//             'message' => "Product with ID {$id} not found"
//         ], 404);
//     }

//     return response()->json([
//         'exists' => true,
//         'product' => $product,
//         'has_farmer' => $product->farmer ? true : false,
//         'farmer_data' => $product->farmer,
//         'status' => $product->status,
//         'quantity' => $product->quantity,
//         'is_available' => $product->status === 'active' && $product->quantity > 0,
//         'product_link' => "/app/products/{$product->id}",
//     ]);
// });

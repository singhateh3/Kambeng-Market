<?php

// routes/api.php

use App\Http\Controllers\Admin\AdminDisputeController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFarmerVerificationController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FarmerProfileController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\FarmerVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ============================================
// PUBLIC ROUTES (No authentication required)
// ============================================

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

// Public product routes (view only)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/categories', [ProductController::class, 'categories']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// Public farmer profile routes
Route::get('/farmers/{userId}/profile', [FarmerProfileController::class, 'publicShow']);

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
        Route::put('/{user}/role', [AdminUserController::class, 'updateRole']);
        Route::post('/{user}/verify', [AdminUserController::class, 'verifyFarmer']);
        Route::patch('/{user}/toggle-status', [AdminUserController::class, 'toggleStatus']);
        Route::delete('/{user}', [AdminUserController::class, 'destroy']);
    });
    
    // Farmer Verification Routes
    Route::prefix('farmers')->group(function () {
        Route::get('/', [AdminFarmerVerificationController::class, 'index']);
        Route::get('/verification/pending', [AdminFarmerVerificationController::class, 'pending']);
        Route::get('/verification/statistics', [AdminFarmerVerificationController::class, 'statistics']);
        Route::get('/verification/{farmer}', [AdminFarmerVerificationController::class, 'show']);
        Route::post('/verification/{farmer}/approve', [AdminFarmerVerificationController::class, 'approve']);
        Route::post('/verification/{farmer}/reject', [AdminFarmerVerificationController::class, 'reject']);
        Route::post('/verification/bulk-approve', [AdminFarmerVerificationController::class, 'bulkApprove']);
        Route::post('/verification/{farmer}/upload-document', [AdminFarmerVerificationController::class, 'uploadDocument']);
    });
    
    // Product Management
    Route::prefix('products')->group(function () {
        Route::get('/', [AdminProductController::class, 'index']);
        Route::get('/{product}', [AdminProductController::class, 'show']);
        Route::delete('/{product}', [AdminProductController::class, 'destroy']);
        Route::post('/bulk-delete', [AdminProductController::class, 'bulkDelete']);
    });
    
    // Order Management
    Route::prefix('orders')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::delete('/{order}', [AdminOrderController::class, 'destroy']);
    });
    
    // Dispute Management
    Route::prefix('disputes')->group(function () {
        Route::get('/', [AdminDisputeController::class, 'index']);
        Route::post('/{order}/resolve', [AdminDisputeController::class, 'resolve']);
    });
});

// ============================================
// PROTECTED ROUTES (Authentication required)
// ============================================
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/refresh-token', [AuthController::class, 'refreshToken']);
    
    // Farmer Profile routes
    Route::prefix('farmer')->group(function () {
        Route::get('/profile', [FarmerProfileController::class, 'show']);
        Route::put('/profile', [FarmerProfileController::class, 'update']);
        Route::post('/profile/verify', [FarmerProfileController::class, 'submitVerification']);
        Route::post('/profile/avatar', [FarmerProfileController::class, 'uploadAvatar']);
        Route::get('/profile/statistics', [FarmerProfileController::class, 'statistics']);
    });
    
    // Farmer verification requests
    Route::post('/farmer/request-verification', [FarmerVerificationController::class, 'requestVerification']);
    Route::get('/farmer/verification-status', [FarmerVerificationController::class, 'status']);
    
    // Product routes (authenticated users)
    Route::prefix('products')->group(function () {
        Route::get('/my-products', [ProductController::class, 'myProducts']);
        Route::post('/', [ProductController::class, 'store']);
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::patch('/{product}/status', [ProductController::class, 'updateStatus']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::delete('/{product}/photo', [ProductController::class, 'deletePhoto']);
        Route::post('/{product}/photos', [ProductController::class, 'addPhotos']);
    });
    
    // Order routes (authenticated users)
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::patch('/{order}/status', [OrderController::class, 'updateStatus']);
    });
    
    // Review routes
    Route::post('/orders/{order}/review', [ReviewController::class, 'store']);
});

// ============================================
// TEST ROUTES (Development only)
// ============================================
Route::get('/admin/test', function () {
    return response()->json([
        'message' => 'Admin access confirmed!',
        'user' => auth()->user()->only(['id', 'name', 'email', 'role'])
    ]);
})->middleware(['auth:sanctum', 'admin']);
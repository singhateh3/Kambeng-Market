<?php

// routes/api.php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FarmerProfileController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes (no authentication required)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

// Public product routes (view only)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// Protected routes (authentication required)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard/statistics', [AdminDashboardController::class, 'statistics']);
    Route::get('/users', [AdminUserController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/refresh-token', [AuthController::class, 'refreshToken']);
    
    // Farmer Profile routes
    Route::get('/farmer/profile', [FarmerProfileController::class, 'show']);
    Route::put('/farmer/profile', [FarmerProfileController::class, 'update']);
    Route::post('/farmer/profile/verify', [FarmerProfileController::class, 'submitVerification']);
    Route::post('/farmer/profile/avatar', [FarmerProfileController::class, 'uploadAvatar']);
    Route::get('/farmer/profile/statistics', [FarmerProfileController::class, 'statistics']);
    
    // Product routes
    Route::get('/products/my-products', [ProductController::class, 'myProducts']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::delete('/products/{product}/photo', [ProductController::class, 'deletePhoto']);
    Route::post('/products/{product}/photos', [ProductController::class, 'addPhotos']);
    
    // Order routes
    // Route::get('/orders', [OrderController::class, 'index']);
    // Route::post('/orders', [OrderController::class, 'store']);
    // Route::get('/orders/{order}', [OrderController::class, 'show']);
    // Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    
    // Review routes
});

// Public farmer profile routes
Route::get('/farmers/{userId}/profile', [FarmerProfileController::class, 'publicShow']);
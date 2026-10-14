<?php

use App\Http\Controllers\Api\V1\Platform\PlatformAuthController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantController;
use App\Http\Controllers\Api\V1\Platform\WorkspaceSignupController;
use Illuminate\Support\Facades\Route;

/*
| Routes that are NOT inside a workspace. Included from routes/api.php (so they are under /api/v1).
*/

// Public: find a workspace / create one
Route::prefix('workspaces')->group(function () {
    Route::get('/available', [WorkspaceSignupController::class, 'available'])->middleware('throttle:60,1');
    Route::get('/{slug}', [WorkspaceSignupController::class, 'show'])->middleware('throttle:60,1')->where('slug', '[A-Za-z0-9-]+');
    Route::post('/register', [WorkspaceSignupController::class, 'register'])->middleware(['throttle:otp', 'turnstile']);
    Route::post('/verify', [WorkspaceSignupController::class, 'verify'])->middleware('throttle:otp');
});

// The platform owner
Route::prefix('platform')->group(function () {
    Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::get('/me', [PlatformAuthController::class, 'me']);
        Route::post('/logout', [PlatformAuthController::class, 'logout']);
        Route::get('/health', [PlatformTenantController::class, 'health']);
        Route::get('/tenants', [PlatformTenantController::class, 'index']);
        Route::post('/tenants', [PlatformTenantController::class, 'store']);
        Route::patch('/tenants/{tenant}', [PlatformTenantController::class, 'update']);
        Route::delete('/tenants/{tenant}', [PlatformTenantController::class, 'destroy']);
    });
});

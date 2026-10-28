<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ListingReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AppAccessController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Developer\ApkUploadController;
use App\Http\Controllers\Api\V1\Developer\ListingController;
use App\Http\Controllers\Api\V1\Developer\ListingMediaController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\StoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('{role}/register', [AuthController::class, 'register'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:6,1')
            ->name('register');

        Route::post('{role}/login', [AuthController::class, 'login'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:10,1')
            ->name('login');

        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
            ->middleware('throttle:5,1')
            ->name('password.email');

        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:10,1')
            ->name('password.reset');

        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');
    });

    Route::get('apps', [StoreController::class, 'index'])->name('apps.index');
    Route::get('apps/{listing:slug}', [StoreController::class, 'show'])->name('apps.show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me', [AuthController::class, 'updateProfile'])->name('me.update');
        Route::put('me/password', [AuthController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('me.password');
        Route::post('me/roles/{role}', [AuthController::class, 'addRole'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:6,1')
            ->name('me.roles.store');

        Route::get('apps/{listing:slug}/access', AppAccessController::class)->name('apps.access');

        Route::get('apps/{listing:slug}/download', DownloadController::class)
            ->middleware('throttle:30,1')
            ->name('apps.download');

        Route::middleware('role:developer')->prefix('developer')->name('developer.')->group(function () {
            Route::apiResource('apps', ListingController::class)->parameters(['apps' => 'listing']);
            Route::post('apps/{listing}/submit', [ListingController::class, 'submit'])->name('apps.submit');
            Route::post('apps/{listing}/duplicate', [ListingController::class, 'duplicate'])->name('apps.duplicate');

            Route::prefix('apps/{listing}/media')->name('apps.media.')->controller(ListingMediaController::class)->group(function () {
                Route::post('{kind}', 'store')->name('store');
                Route::delete('{kind}/{index?}', 'destroy')->whereNumber('index')->name('destroy');
            });

            Route::scopeBindings()->prefix('apps/{listing}/apk-uploads')->name('apps.apk-uploads.')->controller(ApkUploadController::class)->group(function () {
                Route::post('/', 'store')->name('store');
                Route::get('{apkUpload}', 'show')->name('show');
                Route::post('{apkUpload}/chunks', 'chunk')->name('chunk');
                Route::post('{apkUpload}/complete', 'complete')->name('complete');
                Route::delete('{apkUpload}', 'destroy')->name('destroy');
            });
        });

        Route::middleware('role:admin|moderator')->prefix('admin')->name('admin.')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::middleware('permission:'.Permission::ReviewListings->value)->controller(ListingReviewController::class)->group(function () {
                Route::get('listings', 'index')->name('listings.index');
                Route::get('listings/{listing}', 'show')->name('listings.show');
                Route::post('listings/{listing}/approve', 'approve')->name('listings.approve');
                Route::post('listings/{listing}/reject', 'reject')->name('listings.reject');
            });

            Route::middleware('permission:'.Permission::ManageUsers->value)->controller(UserController::class)->group(function () {
                Route::get('users', 'index')->name('users.index');
                Route::put('users/{user}/roles', 'updateRoles')->name('users.roles');
            });
        });
    });
});

<?php

use App\Enums\OtpChannel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DownloadController as AdminDownloadController;
use App\Http\Controllers\Api\V1\Admin\FinanceController;
use App\Http\Controllers\Api\V1\Admin\ListingReviewController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StaffRoleController;
use App\Http\Controllers\Api\V1\Admin\StoreTabController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AppAccessController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\Developer\ApkUploadController;
use App\Http\Controllers\Api\V1\Developer\ListingController;
use App\Http\Controllers\Api\V1\Developer\ListingDownloadController;
use App\Http\Controllers\Api\V1\Developer\ListingMediaController;
use App\Http\Controllers\Api\V1\Developer\PayoutAccountController;
use App\Http\Controllers\Api\V1\Developer\WalletController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        // the website calls it twice per signup: once to validate, once with the codes
        Route::post('{role}/register', [AuthController::class, 'register'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:12,1')
            ->name('register');

        Route::post('{role}/login', [AuthController::class, 'login'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:10,1')
            ->name('login');

        Route::post('{role}/login/code', [AuthController::class, 'loginWithCode'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:10,1')
            ->name('login.code');

        // one-time codes for signup, login and password reset, by email or SMS
        Route::post('otp', [OtpController::class, 'send'])
            ->middleware('throttle:5,1')
            ->name('otp.send');

        Route::post('reset-password/code', [PasswordResetController::class, 'resetWithCode'])
            ->middleware('throttle:10,1')
            ->name('password.reset.code');

        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
            ->middleware('throttle:5,1')
            ->name('password.email');

        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:10,1')
            ->name('password.reset');

        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'account:user'])
            ->name('logout');
    });

    Route::get('apps', [StoreController::class, 'index'])->name('apps.index');
    Route::get('store-tabs', [StoreController::class, 'tabs'])->name('store-tabs.index');
    Route::get('apps/{listing:slug}', [StoreController::class, 'show'])->name('apps.show');

    // website accounts: players and developers
    Route::middleware(['auth:sanctum', 'account:user'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me', [AuthController::class, 'updateProfile'])->name('me.update');
        Route::put('me/organization', [AuthController::class, 'updateOrganization'])->name('me.organization');
        Route::put('me/password', [AuthController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('me.password');
        Route::post('me/avatar', [AvatarController::class, 'store'])->middleware('throttle:10,1')->name('me.avatar.store');
        Route::delete('me/avatar', [AvatarController::class, 'destroy'])->name('me.avatar.destroy');
        Route::post('me/verification/{channel}/send', [VerificationController::class, 'send'])
            ->whereIn('channel', OtpChannel::values())
            ->middleware('throttle:5,1')
            ->name('me.verification.send');
        Route::post('me/verification/{channel}', [VerificationController::class, 'verify'])
            ->whereIn('channel', OtpChannel::values())
            ->middleware('throttle:10,1')
            ->name('me.verification.verify');
        Route::delete('me/mobile', [VerificationController::class, 'destroyMobile'])->name('me.mobile.destroy');
        Route::post('me/roles/{role}', [AuthController::class, 'addRole'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:6,1')
            ->name('me.roles.store');

        Route::get('apps/{listing:slug}/access', AppAccessController::class)->name('apps.access');

        Route::get('apps/{listing:slug}/download', DownloadController::class)
            ->middleware('throttle:30,1')
            ->name('apps.download');

        Route::middleware('role:developer')->prefix('developer')->name('developer.')->group(function () {
            Route::get('payout-account', [PayoutAccountController::class, 'show'])->name('payout-account.show');
            Route::put('payout-account', [PayoutAccountController::class, 'update'])->name('payout-account.update');
            Route::get('wallet', WalletController::class)->name('wallet');

            Route::apiResource('apps', ListingController::class)->parameters(['apps' => 'listing']);
            Route::post('apps/{listing}/submit', [ListingController::class, 'submit'])->name('apps.submit');
            Route::post('apps/{listing}/duplicate', [ListingController::class, 'duplicate'])->name('apps.duplicate');
            Route::get('apps/{listing}/downloads', ListingDownloadController::class)->name('apps.downloads');

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
    });

    // staff accounts of the admin panel (App\Models\Admin)
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('auth/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('auth.login');

        Route::middleware(['auth:sanctum', 'account:admin'])->group(function () {
            Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AdminAuthController::class, 'me'])->name('me');
            Route::put('me', [AdminAuthController::class, 'updateProfile'])->name('me.update');
            Route::put('me/password', [AdminAuthController::class, 'updatePassword'])->middleware('throttle:6,1')->name('me.password');
            Route::post('me/avatar', [AdminAuthController::class, 'storeAvatar'])->middleware('throttle:10,1')->name('me.avatar.store');
            Route::delete('me/avatar', [AdminAuthController::class, 'destroyAvatar'])->name('me.avatar.destroy');
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::middleware('permission:'.Permission::ReviewListings->value)->controller(ListingReviewController::class)->group(function () {
                Route::get('listings', 'index')->name('listings.index');
                Route::get('listings/{listing}', 'show')->name('listings.show');
                Route::post('listings/{listing}/approve', 'approve')->name('listings.approve');
                Route::post('listings/{listing}/reject', 'reject')->name('listings.reject');
                Route::get('apps/{listing:slug}/download', 'download')->name('apps.download');
            });

            Route::middleware('permission:'.Permission::ManageUsers->value)->controller(UserController::class)->group(function () {
                Route::get('users', 'index')->name('users.index');
                Route::put('users/{user}/roles', 'updateRoles')->name('users.roles');
            });

            Route::middleware('permission:'.Permission::ViewStatistics->value)->controller(AdminDownloadController::class)->group(function () {
                Route::get('statistics/downloads', 'summary')->name('statistics.downloads');
                Route::get('downloads', 'index')->name('downloads.index');
                Route::get('listings/{listing}/downloads', 'listing')->name('listings.downloads');
            });

            Route::apiResource('store-tabs', StoreTabController::class)
                ->except('show')
                ->middleware('permission:'.Permission::CurateStore->value);

            Route::middleware('permission:'.Permission::ManageFinance->value)->controller(FinanceController::class)->group(function () {
                Route::get('finance', 'show')->name('finance.show');
                Route::put('finance/commission', 'updateDefaultCommission')->name('finance.commission');
                Route::put('listings/{listing}/commission', 'updateListingCommission')->name('listings.commission');
            });

            Route::middleware('permission:'.Permission::ManageSettings->value)->controller(SettingController::class)->group(function () {
                Route::get('settings', 'show')->name('settings.show');
                Route::put('settings', 'update')->name('settings.update');
                Route::get('otp-lockouts', 'lockouts')->name('otp-lockouts.index');
                Route::delete('otp-lockouts/{otpLockout}', 'unlock')->name('otp-lockouts.destroy');
            });

            Route::middleware('permission:'.Permission::ManageStaff->value)->group(function () {
                Route::get('permissions', [StaffRoleController::class, 'permissions'])->name('permissions.index');
                Route::apiResource('roles', StaffRoleController::class)->except('show');
                Route::apiResource('staff', StaffController::class)->except('show')->parameters(['staff' => 'admin']);
            });
        });
    });
});

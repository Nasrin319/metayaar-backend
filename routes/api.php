<?php

use App\Enums\OtpChannel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\Admin\AdminLoginController;
use App\Http\Controllers\Api\V1\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Api\V1\Admin\ArticleImageController;
use App\Http\Controllers\Api\V1\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\CommentReviewController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DownloadController as AdminDownloadController;
use App\Http\Controllers\Api\V1\Admin\FinanceController;
use App\Http\Controllers\Api\V1\Admin\ListingReviewController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\PayoutRequestController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StaffRoleController;
use App\Http\Controllers\Api\V1\Admin\StoreTabController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AppAccessController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\Developer\ApkUploadController;
use App\Http\Controllers\Api\V1\Developer\ListingController;
use App\Http\Controllers\Api\V1\Developer\ListingDownloadController;
use App\Http\Controllers\Api\V1\Developer\ListingMediaController;
use App\Http\Controllers\Api\V1\Developer\PayoutAccountController;
use App\Http\Controllers\Api\V1\Developer\PayoutRequestController as DeveloperPayoutRequestController;
use App\Http\Controllers\Api\V1\Developer\PrivateAccessController;
use App\Http\Controllers\Api\V1\Developer\WalletController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // throttle:login, :otp and :comments are per-minute limits admins set in the panel (AppServiceProvider)
    Route::prefix('auth')->name('auth.')->group(function () {
        // the website calls it twice per signup: once to validate, once with the codes
        Route::post('{role}/register', [AuthController::class, 'register'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:12,1')
            ->name('register');

        Route::post('{role}/login', [AuthController::class, 'login'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:login')
            ->name('login');

        Route::post('{role}/login/code', [AuthController::class, 'loginWithCode'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:login')
            ->name('login.code');

        // one-time codes for signup, login and password reset, by email or SMS
        Route::post('otp', [OtpController::class, 'send'])
            ->middleware('throttle:otp')
            ->name('otp.send');

        Route::post('reset-password/code', [PasswordResetController::class, 'resetWithCode'])
            ->middleware('throttle:login')
            ->name('password.reset.code');

        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
            ->middleware('throttle:otp')
            ->name('password.email');

        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:login')
            ->name('password.reset');

        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'account:user'])
            ->name('logout');
    });

    Route::get('apps', [StoreController::class, 'index'])->name('apps.index');
    Route::get('store-tabs', [StoreController::class, 'tabs'])->name('store-tabs.index');
    Route::get('apps/{listing:slug}', [StoreController::class, 'show'])->name('apps.show');
    // approved comments; a bearer token, when sent, adds the viewer's own comments still waiting
    Route::get('apps/{listing:slug}/comments', [CommentController::class, 'index'])->name('apps.comments.index');

    Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::get('articles/{article:slug}', [ArticleController::class, 'show'])->name('articles.show');
    Route::get('banners', BannerController::class)->name('banners.index');

    // website accounts: members and developers
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
            ->middleware('throttle:otp')
            ->name('me.verification.send');
        Route::post('me/verification/{channel}', [VerificationController::class, 'verify'])
            ->whereIn('channel', OtpChannel::values())
            ->middleware('throttle:login')
            ->name('me.verification.verify');
        Route::delete('me/mobile', [VerificationController::class, 'destroyMobile'])->name('me.mobile.destroy');
        Route::post('me/roles/{role}', [AuthController::class, 'addRole'])
            ->whereIn('role', Role::values())
            ->middleware('throttle:6,1')
            ->name('me.roles.store');

        Route::get('me/private-apps', [StoreController::class, 'privateApps'])->name('me.private-apps');
        Route::get('apps/{listing:slug}/access', AppAccessController::class)->name('apps.access');

        Route::post('apps/{listing:slug}/comments', [CommentController::class, 'store'])
            ->middleware('throttle:comments')
            ->name('apps.comments.store');
        Route::post('comments/{comment}/replies', [CommentController::class, 'reply'])
            ->middleware('throttle:comments')
            ->name('comments.replies.store');
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

        Route::get('apps/{listing:slug}/download', DownloadController::class)
            ->middleware('throttle:30,1')
            ->name('apps.download');

        Route::middleware('role:developer')->prefix('developer')->name('developer.')->group(function () {
            Route::get('payout-account', [PayoutAccountController::class, 'show'])->name('payout-account.show');
            Route::put('payout-account', [PayoutAccountController::class, 'update'])->name('payout-account.update');
            Route::get('wallet', WalletController::class)->name('wallet');
            Route::get('payout-requests', [DeveloperPayoutRequestController::class, 'index'])->name('payout-requests.index');
            Route::post('payout-requests', [DeveloperPayoutRequestController::class, 'store'])->middleware('throttle:6,1')->name('payout-requests.store');

            Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('unread-count', 'unreadCount')->name('unread-count');
                Route::post('read-all', 'readAll')->name('read-all');
                Route::post('{notification}/read', 'read')->whereUuid('notification')->name('read');
            });

            Route::apiResource('apps', ListingController::class)->parameters(['apps' => 'listing']);
            Route::post('apps/{listing}/submit', [ListingController::class, 'submit'])->name('apps.submit');
            Route::post('apps/{listing}/duplicate', [ListingController::class, 'duplicate'])->name('apps.duplicate');
            Route::put('apps/{listing}/private-access', [PrivateAccessController::class, 'update'])->name('apps.private-access');
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
        Route::get('auth/captcha', [AdminAuthController::class, 'captcha'])
            ->middleware('throttle:60,1')
            ->name('auth.captcha');
        Route::post('auth/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('auth.login');

        Route::middleware(['auth:sanctum', 'account:admin'])->group(function () {
            Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AdminAuthController::class, 'me'])->name('me');
            Route::put('me', [AdminAuthController::class, 'updateProfile'])->name('me.update');
            Route::put('me/password', [AdminAuthController::class, 'updatePassword'])->middleware('throttle:6,1')->name('me.password');
            Route::post('me/avatar', [AdminAuthController::class, 'storeAvatar'])->middleware('throttle:10,1')->name('me.avatar.store');
            Route::delete('me/avatar', [AdminAuthController::class, 'destroyAvatar'])->name('me.avatar.destroy');
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('unread-count', 'unreadCount')->name('unread-count');
                Route::post('read-all', 'readAll')->name('read-all');
                Route::post('{notification}/read', 'read')->whereUuid('notification')->name('read');
            });

            Route::middleware('permission:'.Permission::ReviewListings->value)->controller(ListingReviewController::class)->group(function () {
                Route::get('listings', 'index')->name('listings.index');
                Route::get('listings/{listing}', 'show')->name('listings.show');
                Route::post('listings/{listing}/approve', 'approve')->name('listings.approve');
                Route::post('listings/{listing}/reject', 'reject')->name('listings.reject');
                Route::scopeBindings()->group(function () {
                    Route::post('listings/{listing}/invites/{invite}/approve', 'approveInvite')->name('listings.invites.approve');
                    Route::post('listings/{listing}/invites/{invite}/reject', 'rejectInvite')->name('listings.invites.reject');
                });
                Route::get('apps/{listing:slug}/download', 'download')->name('apps.download');
            });

            Route::middleware('permission:'.Permission::ModerateComments->value)->controller(CommentReviewController::class)->group(function () {
                Route::get('comments', 'index')->name('comments.index');
                Route::post('comments/{comment}/approve', 'approve')->name('comments.approve');
                Route::post('comments/{comment}/reject', 'reject')->name('comments.reject');
                Route::delete('comments/{comment}', 'destroy')->name('comments.destroy');
            });

            Route::middleware('permission:'.Permission::ManageUsers->value)->controller(UserController::class)->group(function () {
                Route::get('users', 'index')->name('users.index');
                Route::put('users/{user}/roles', 'updateRoles')->name('users.roles');
                Route::post('users/{user}/disable', 'disable')->name('users.disable');
                Route::post('users/{user}/enable', 'enable')->name('users.enable');
                Route::delete('users/{user}', 'destroy')->name('users.destroy');
            });

            Route::middleware('permission:'.Permission::ViewStatistics->value)->controller(AdminDownloadController::class)->group(function () {
                Route::get('statistics/downloads', 'summary')->name('statistics.downloads');
                Route::get('downloads', 'index')->name('downloads.index');
                Route::get('listings/{listing}/downloads', 'listing')->name('listings.downloads');
            });

            Route::apiResource('store-tabs', StoreTabController::class)
                ->except('show')
                ->middleware('permission:'.Permission::CurateStore->value);

            Route::middleware('permission:'.Permission::ManageContent->value)->group(function () {
                Route::apiResource('articles', AdminArticleController::class);
                Route::post('article-images', [ArticleImageController::class, 'store'])->middleware('throttle:60,1')->name('article-images.store');
                Route::apiResource('banners', AdminBannerController::class)->except('show');
            });

            Route::middleware('permission:'.Permission::ManageFinance->value)->controller(FinanceController::class)->group(function () {
                Route::get('finance', 'show')->name('finance.show');
                Route::put('finance/commission', 'updateDefaultCommission')->name('finance.commission');
                Route::put('listings/{listing}/commission', 'updateListingCommission')->name('listings.commission');
            });

            Route::middleware('permission:'.Permission::ManageFinance->value)->controller(PayoutRequestController::class)->prefix('payout-requests')->name('payout-requests.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('{payoutRequest}/pay', 'pay')->name('pay');
                Route::post('{payoutRequest}/reject', 'reject')->name('reject');
            });

            Route::middleware('permission:'.Permission::ManageSettings->value)->controller(SettingController::class)->group(function () {
                Route::get('settings', 'show')->name('settings.show');
                Route::put('settings', 'update')->name('settings.update');
                Route::get('otp-lockouts', 'lockouts')->name('otp-lockouts.index');
                Route::delete('otp-lockouts/{otpLockout}', 'unlock')->name('otp-lockouts.destroy');
            });

            Route::middleware('permission:'.Permission::ManageStaff->value)->group(function () {
                Route::get('permissions', [StaffRoleController::class, 'permissions'])->name('permissions.index');
                Route::get('login-history', [AdminLoginController::class, 'index'])->name('login-history.index');
                Route::apiResource('roles', StaffRoleController::class)->except('show');
                Route::apiResource('staff', StaffController::class)->except('show')->parameters(['staff' => 'admin']);
            });
        });
    });
});

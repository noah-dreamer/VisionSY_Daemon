<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\GalleryController as AdminGalleryController;
use App\Http\Controllers\Admin\StorageSourceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserGroupController;
use App\Http\Controllers\Auth\CaptchaController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Media\GalleryController as MediaGalleryController;
use App\Http\Controllers\Media\MediaFileController;
use App\Http\Controllers\Media\MediaLibraryController;
use App\Http\Controllers\Media\MediaUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/* ---------- 访客 ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:public-registration');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/captcha/challenge', [CaptchaController::class, 'challenge'])
        ->middleware('throttle:30,1')->name('captcha.challenge');
    Route::post('/captcha/verify', [CaptchaController::class, 'verify'])
        ->middleware('throttle:30,1')->name('captcha.verify');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

/* ---------- 登录用户 ---------- */
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verify-code', [EmailVerificationController::class, 'verifyCode'])
        ->middleware('throttle:10,1')->name('verification.verify-code');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('/dashboard/recheck-activation', [DashboardController::class, 'recheckActivation'])
        ->name('dashboard.recheck');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::get('/password', [SettingsController::class, 'password'])->name('password');
        Route::get('/profile/check-name', [SettingsController::class, 'checkName'])->name('profile.check-name');
        Route::patch('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
        Route::post('/avatar', [SettingsController::class, 'updateAvatar'])->name('avatar.update');
        Route::put('/password', [SettingsController::class, 'updatePassword'])->name('password.update');
    });
});



/* ---------- 内置媒体库（DAM） ---------- */
Route::prefix('media')->name('media.')->group(function () {
    Route::get('/', [MediaLibraryController::class, 'index'])->name('index');

    Route::get('/galleries', [MediaGalleryController::class, 'index'])->name('galleries.index');
    Route::get('/galleries/{gallery}', [MediaGalleryController::class, 'show'])->name('galleries.show');

    Route::get('/{resource}/transfer', [MediaFileController::class, 'transfer'])->name('transfer');

    Route::middleware('auth')->group(function () {
        Route::get('/upload', [MediaUploadController::class, 'create'])->name('create');
        Route::post('/upload', [MediaUploadController::class, 'store'])
            ->middleware('throttle:30,1')->name('store');

        Route::get('/{resource}/preview', [MediaFileController::class, 'preview'])->name('preview');
        Route::get('/{resource}/download/original', [MediaFileController::class, 'downloadOriginal'])->name('download.original');
        Route::get('/{resource}/download/watermarked', [MediaFileController::class, 'downloadWatermarked'])->name('download.watermarked');
        Route::get('/{resource}/download/watermarked-png', [MediaFileController::class, 'downloadWatermarkedPng'])->name('download.watermarked-png');

        Route::get('/{resource}/edit', [MediaLibraryController::class, 'edit'])->name('edit');
        Route::put('/{resource}', [MediaLibraryController::class, 'update'])->name('update');
        Route::post('/{resource}/toggle-archive', [MediaLibraryController::class, 'toggleArchive'])->name('toggle-archive');
        Route::delete('/{resource}', [MediaLibraryController::class, 'destroy'])->name('destroy');
    });

    Route::get('/{resource}/thumb', [MediaFileController::class, 'thumb'])->name('thumb');
    Route::get('/{resource}', [MediaLibraryController::class, 'show'])->name('show');
});

/* ---------- 管理后台（仅 system_admin） ---------- */
Route::middleware(['auth', 'system_admin', 'legacy_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/user-groups', [UserGroupController::class, 'index'])->name('user-groups.index');
    Route::get('/user-groups/create', [UserGroupController::class, 'create'])->name('user-groups.create');
    Route::post('/user-groups', [UserGroupController::class, 'store'])->name('user-groups.store');
    Route::get('/user-groups/{userGroup}/edit', [UserGroupController::class, 'edit'])->name('user-groups.edit');
    Route::put('/user-groups/{userGroup}', [UserGroupController::class, 'update'])->name('user-groups.update');
    Route::delete('/user-groups/{userGroup}', [UserGroupController::class, 'destroy'])->name('user-groups.destroy');

    Route::get('/storage-sources', [StorageSourceController::class, 'index'])->name('storage-sources.index');
    Route::get('/storage-sources/create', [StorageSourceController::class, 'create'])->name('storage-sources.create');
    Route::post('/storage-sources', [StorageSourceController::class, 'store'])->name('storage-sources.store');
    Route::get('/storage-sources/{storageSource}/edit', [StorageSourceController::class, 'edit'])->name('storage-sources.edit');
    Route::put('/storage-sources/{storageSource}', [StorageSourceController::class, 'update'])->name('storage-sources.update');
    Route::delete('/storage-sources/{storageSource}', [StorageSourceController::class, 'destroy'])->name('storage-sources.destroy');
    Route::post('/storage-sources/{storageSource}/verify', [StorageSourceController::class, 'verify'])->name('storage-sources.verify');
    Route::post('/storage-sources/{storageSource}/scan', [StorageSourceController::class, 'scan'])->name('storage-sources.scan');
    Route::post('/storage-sources/{storageSource}/mount', [App\Http\Controllers\Admin\MountController::class, 'mount'])->name('storage-sources.mount');
    Route::post('/storage-sources/{storageSource}/unmount', [App\Http\Controllers\Admin\MountController::class, 'unmount'])->name('storage-sources.unmount');
    Route::post('/storage-sources/{storageSource}/test-connection', [App\Http\Controllers\Admin\MountController::class, 'testConnection'])->name('storage-sources.test-connection');

    Route::get('/galleries', [AdminGalleryController::class, 'index'])->name('galleries.index');
    Route::get('/galleries/create', [AdminGalleryController::class, 'create'])->name('galleries.create');
    Route::post('/galleries', [AdminGalleryController::class, 'store'])->name('galleries.store');
    Route::get('/galleries/{gallery}/edit', [AdminGalleryController::class, 'edit'])->name('galleries.edit');
    Route::put('/galleries/{gallery}', [AdminGalleryController::class, 'update'])->name('galleries.update');
    Route::delete('/galleries/{gallery}', [AdminGalleryController::class, 'destroy'])->name('galleries.destroy');
    Route::post('/galleries/{gallery}/scan', [AdminGalleryController::class, 'scan'])->name('galleries.scan');

    Route::post('/media/{mediaResource}/retry-derivatives', [App\Http\Controllers\Admin\DerivativeRetryController::class, 'retryResource'])->name('media.retry-derivatives');
    Route::post('/galleries/{gallery}/retry-derivatives', [App\Http\Controllers\Admin\DerivativeRetryController::class, 'retryGallery'])->name('galleries.retry-derivatives');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});

<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CampusIpRangeController;
use App\Http\Controllers\Admin\OAuthClientController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OAuth\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/* ---------- 访客 ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

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
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('/dashboard/recheck-activation', [DashboardController::class, 'recheckActivation'])
        ->name('dashboard.recheck');

    // OAuth2 授权端点（需登录；token/userinfo 在下方无会话区）
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->name('oauth.authorize');
});

/* ---------- OAuth2 无会话端点 ---------- */
Route::post('/oauth/token', [OAuthController::class, 'token'])->name('oauth.token');
Route::get('/oauth/userinfo', [OAuthController::class, 'userinfo'])->name('oauth.userinfo');

/* ---------- 管理后台（仅 system_admin） ---------- */
Route::middleware(['auth', 'system_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

    Route::get('/campus-ips', [CampusIpRangeController::class, 'index'])->name('campus-ips.index');
    Route::post('/campus-ips', [CampusIpRangeController::class, 'store'])->name('campus-ips.store');
    Route::put('/campus-ips/{range}', [CampusIpRangeController::class, 'update'])->name('campus-ips.update');
    Route::post('/campus-ips/{range}/toggle', [CampusIpRangeController::class, 'toggle'])->name('campus-ips.toggle');
    Route::delete('/campus-ips/{range}', [CampusIpRangeController::class, 'destroy'])->name('campus-ips.destroy');

    Route::get('/oauth-clients', [OAuthClientController::class, 'index'])->name('oauth-clients.index');
    Route::post('/oauth-clients', [OAuthClientController::class, 'store'])->name('oauth-clients.store');
    Route::delete('/oauth-clients/{client}', [OAuthClientController::class, 'destroy'])->name('oauth-clients.destroy');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});

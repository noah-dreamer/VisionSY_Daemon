<?php
use App\Http\Controllers\Management\AccountsController as Accounts;
use App\Http\Controllers\Management\LibraryController as Library;
use App\Http\Controllers\Management\OperationsController as Operations;
use Illuminate\Support\Facades\Route;

// Signed, short-lived thumbnail URLs allow browser → daemon traffic without disclosing an API key.
Route::get('/management/v1/thumbnails/{resource}', [Library::class, 'thumbnail'])->middleware(['signed', 'throttle:120,1'])->name('management.thumbnail');
Route::prefix('management/v1')->middleware(['management_api', 'throttle:300,1'])->group(function () {
    Route::get('/mail', [\App\Http\Controllers\Management\MailController::class, 'show']);
    Route::put('/mail', [\App\Http\Controllers\Management\MailController::class, 'update']);
    Route::post('/mail/test', [\App\Http\Controllers\Management\MailController::class, 'test']);
    Route::get('/info', [Operations::class, 'info']);
    Route::get('/overview', [Operations::class, 'overview']);
    Route::get('/users', [Accounts::class, 'users']);
    Route::post('/users', [Accounts::class, 'saveUser']);
    Route::put('/users/{user}', [Accounts::class, 'saveUser']);
    Route::delete('/users/{user}', [Accounts::class, 'deleteUser']);
    Route::post('/users/{user}/reset-password', [Accounts::class, 'resetPassword']);
    Route::get('/groups', [Accounts::class, 'groups']);
    Route::post('/groups', [Accounts::class, 'saveGroup']);
    Route::put('/groups/{group}', [Accounts::class, 'saveGroup']);
    Route::delete('/groups/{group}', [Accounts::class, 'deleteGroup']);
    Route::get('/sources', [Library::class, 'sources']);
    Route::post('/sources', [Library::class, 'saveSource']);
    Route::put('/sources/{source}', [Library::class, 'saveSource']);
    Route::delete('/sources/{source}', [Library::class, 'deleteSource']);
    Route::post('/sources/{source}/{action}', [Library::class, 'sourceAction'])->whereIn('action', ['scan', 'verify']);
    Route::get('/galleries', [Library::class, 'galleries']);
    Route::post('/galleries', [Library::class, 'saveGallery']);
    Route::put('/galleries/{gallery}', [Library::class, 'saveGallery']);
    Route::delete('/galleries/{gallery}', [Library::class, 'deleteGallery']);
    Route::post('/galleries/{gallery}/{action}', [Library::class, 'galleryAction'])->whereIn('action', ['scan', 'retry-derivatives']);
    Route::get('/resources', [Library::class, 'resources']);
    Route::put('/resources/{resource}', [Library::class, 'saveResource']);
    Route::delete('/resources/{resource}', [Library::class, 'deleteResource']);
    Route::post('/resources/{resource}/retry-derivatives', [Library::class, 'retryResource']);
    Route::get('/tasks', [Operations::class, 'tasks']);
    Route::post('/tasks/{uuid}/{action}', [Operations::class, 'taskAction'])->whereUuid('uuid')->whereIn('action', ['retry', 'forget']);
    Route::post('/worker/restart', [Operations::class, 'restartWorker']);
    Route::get('/audit', [Operations::class, 'audit']);
    Route::get('/backups', [Operations::class, 'backups']);
    Route::post('/backups', [Operations::class, 'createBackup']);
    Route::get('/backups/{backup}/download', [Operations::class, 'downloadBackup'])->whereUuid('backup');
    Route::delete('/backups/{backup}', [Operations::class, 'deleteBackup'])->whereUuid('backup');
});

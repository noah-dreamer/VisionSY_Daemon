<?php

use App\Http\Controllers\Api\TransferTokenController;
use Illuminate\Support\Facades\Route;

/*
| 内部 API：经 internal_api 中间件（X-Internal-Secret）鉴权。
| INTERNAL_API_SECRET 生产真实值来自`<SECRET_MANAGER>`。
*/
Route::middleware(['internal_api', 'throttle:internal-api'])->group(function () {
    Route::post('/transfer-tokens', [TransferTokenController::class, 'store']);
    Route::get('/transfer-tokens/validate', [TransferTokenController::class, 'validateToken']);
    Route::post('/transfer-tokens/consume', [TransferTokenController::class, 'consume']);
    Route::get('/redirect-decision', [TransferTokenController::class, 'redirectDecision']);
});

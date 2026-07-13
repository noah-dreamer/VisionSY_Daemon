<?php

use App\Http\Controllers\Api\TransferTokenController;
use Illuminate\Support\Facades\Route;

/*
| 内部 API：供 ResourceSpace / 反向代理网关 / Nginx auth_request 调用。
| 鉴权：X-Internal-Secret 头（INTERNAL_API_SECRET，生产值来自`<SECRET_MANAGER>`）。
*/
Route::middleware(['internal_api', 'throttle:internal-api'])->group(function () {
    Route::post('/transfer-tokens', [TransferTokenController::class, 'store']);
    Route::get('/transfer-tokens/validate', [TransferTokenController::class, 'validateToken']);
    Route::post('/transfer-tokens/consume', [TransferTokenController::class, 'consume']);
    Route::get('/redirect-decision', [TransferTokenController::class, 'redirectDecision']);
});

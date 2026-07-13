<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Captcha\PlayCaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PlayCaptcha（抓娃娃机人机验证）服务端端点。
 * GET  /captcha/challenge → 领取一次性挑战 id
 * POST /captcha/verify    → 通关后换取一次性 pass token
 */
class CaptchaController extends Controller
{
    public function __construct(private PlayCaptchaService $captcha)
    {
    }

    public function challenge(): JsonResponse
    {
        return response()->json($this->captcha->createChallenge());
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->captcha->verify($data['id']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}

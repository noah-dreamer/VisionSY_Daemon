<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PlayCaptcha（github.com/mortspace/playcaptcha）服务端配套层。
 *
 * 上游是一个纯前端 React"抓娃娃机"人机验证组件（ClawCaptcha）：
 * 玩家用摇杆把爪子移到指定玩具上方、抓起并投入舱口即通过（onVerify）。
 * 上游 README 明确其定位：checks that someone is *playing* —— 应放在
 * 真实校验之前而不是取而代之。因此服务端保留三道防线：
 *
 *   1. 一次性挑战 id：页面加载领取，verify 时消费，无法重放；
 *   2. 人类耗时下限：从领取到通关必须 ≥ min_solve_seconds（爪机一局
 *      至少需要移动/抓取/搬运/投放，脚本秒过即拒绝）；
 *   3. 一次性 pass token：verify 通过后签发，登录表单提交时消费，
 *      带 TTL，同样无法重放。
 */
class PlayCaptchaService
{
    private const CHALLENGE_PREFIX = 'playcaptcha:challenge:';
    private const PASS_PREFIX = 'playcaptcha:pass:';

    /** 领取一次挑战。 */
    public function createChallenge(): array
    {
        $id = (string) Str::ulid();

        Cache::put(self::CHALLENGE_PREFIX.$id, [
            'issued_at' => microtime(true),
        ], now()->addSeconds($this->challengeTtl()));

        return ['id' => $id, 'ttl' => $this->challengeTtl()];
    }

    /**
     * 通关校验：挑战存在（未消费、未过期）且耗时达到人类下限。
     *
     * @return array{ok: bool, token?: string, reason?: string}
     */
    public function verify(string $id): array
    {
        $key = self::CHALLENGE_PREFIX.$id;
        $challenge = Cache::get($key);

        if ($challenge === null) {
            return ['ok' => false, 'reason' => 'challenge_expired'];
        }

        // 一次性：无论结果如何都消费掉
        Cache::forget($key);

        $elapsed = microtime(true) - (float) $challenge['issued_at'];
        if ($elapsed < $this->minSolveSeconds()) {
            return ['ok' => false, 'reason' => 'too_fast'];
        }

        $token = (string) Str::ulid().Str::random(24);
        Cache::put(self::PASS_PREFIX.hash('sha256', $token), true, now()->addSeconds($this->passTtl()));

        return ['ok' => true, 'token' => $token];
    }

    /** 消费一次性 pass token（登录提交时调用）。 */
    public function consumePass(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $key = self::PASS_PREFIX.hash('sha256', $token);

        if (Cache::get($key) !== true) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    public function enabled(): bool
    {
        return (bool) config('visionsy.captcha.enabled');
    }

    private function challengeTtl(): int
    {
        return (int) config('visionsy.captcha.challenge_ttl', 180);
    }

    private function passTtl(): int
    {
        return (int) config('visionsy.captcha.pass_ttl', 300);
    }

    private function minSolveSeconds(): float
    {
        return (float) config('visionsy.captcha.min_solve_seconds', 3.0);
    }
}

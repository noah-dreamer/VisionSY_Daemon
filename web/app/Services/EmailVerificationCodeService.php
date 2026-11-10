<?php

namespace App\Services;

use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

/**
 * 注册邮箱验证码：
 *   - 6 位数字，默认 10 分钟有效（EMAIL_CODE_TTL_SECONDS）；
 *   - 重发冷却默认 60 秒（EMAIL_CODE_RESEND_COOLDOWN）；
 *   - 单个验证码最多尝试 5 次，超限作废；
 *   - 库中只存 sha256(code)，明文只出现在邮件里。
 */
class EmailVerificationCodeService
{
    public const RESULT_OK = 'ok';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_EXPIRED = 'expired';
    public const RESULT_TOO_MANY_ATTEMPTS = 'too_many_attempts';
    public const RESULT_NOT_FOUND = 'not_found';

    public function __construct(private AuditLogger $audit)
    {
    }

    /** 距离下一次可重发还需等待的秒数（0 表示可发）。 */
    public function resendAvailableIn(User $user): int
    {
        $last = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->latest('sent_at')
            ->first();

        if ($last === null) {
            return 0;
        }

        $cooldown = (int) config('visionsy.email_code.resend_cooldown_seconds', 60);
        $elapsed = $last->sent_at->diffInSeconds(now());

        return (int) max(0, $cooldown - $elapsed);
    }

    /** 生成并发送验证码。返回 false 表示仍在冷却期。 */
    public function issue(User $user): bool
    {
        app(MailConfiguration::class)->prepare();
        return DB::transaction(function () use ($user) {
            // Serialize resend attempts for one account; failed delivery rolls back the cooldown/code.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($this->resendAvailableIn($user) > 0) {
                return false;
            }
            EmailVerificationCode::where('user_id', $user->id)->delete();
            $length = (int) config('visionsy.email_code.length', 6);
            $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
            $ttl = (int) config('visionsy.email_code.ttl_seconds', 600);
            EmailVerificationCode::create([
                'user_id' => $user->id, 'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addSeconds($ttl), 'sent_at' => now(),
            ]);
            try {
                Mail::to($user->email)->send(new EmailVerificationCodeMail($user, $code, (int) ceil($ttl / 60)));
            } catch (\Throwable $error) {
                // Do not expose SMTP credentials or the verification code in errors/logs.
                throw new MailDeliveryException('暂时无法发送验证邮件，请稍后重试或联系管理员。');
            }
            $this->audit->log('user.email_code_sent', $user, 'user', (string) $user->id);
            return true;
        });
    }

    /** 校验验证码。成功时删除该用户全部验证码。 */
    public function verify(User $user, string $code): string
    {
        $record = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->latest('sent_at')
            ->first();

        if ($record === null) {
            return self::RESULT_NOT_FOUND;
        }
        if ($record->isExpired()) {
            return self::RESULT_EXPIRED;
        }

        $maxAttempts = (int) config('visionsy.email_code.max_attempts', 5);
        if ($record->attempts >= $maxAttempts) {
            return self::RESULT_TOO_MANY_ATTEMPTS;
        }

        $record->increment('attempts');

        if (! hash_equals($record->code_hash, hash('sha256', trim($code)))) {
            return $record->attempts >= $maxAttempts
                ? self::RESULT_TOO_MANY_ATTEMPTS
                : self::RESULT_INVALID;
        }

        EmailVerificationCode::query()->where('user_id', $user->id)->delete();

        return self::RESULT_OK;
    }
}

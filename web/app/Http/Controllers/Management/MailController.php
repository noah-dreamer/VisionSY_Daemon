<?php
namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\MailConfiguration;
use App\Services\MailDeliveryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class MailController extends Controller
{
    public function show(MailConfiguration $mail)
    {
        return response()->json(['data' => $mail->publicSettings()]);
    }
    public function update(Request $request, MailConfiguration $mail, AuditLogger $audit)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9._:\-]+$/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'security' => ['required', Rule::in(['smtps', 'starttls', 'none'])],
            'username' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'max:1024'],
            'clear_password' => ['boolean'], 'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:100'],
        ]);
        $data['username'] = $data['username'] ?? '';
        $mail->save($data);
        $audit->log('mail.settings_updated', $request->user());
        return response()->json(['message' => '发信设置已保存，即刻生效。请发送测试邮件确认可用。', 'data' => $mail->publicSettings()]);
    }
    public function test(Request $request, MailConfiguration $mail, AuditLogger $audit)
    {
        $data = $request->validate(['recipient' => ['required', 'email', 'max:255']]);
        abort_unless($mail->current()['enabled'], 422, '请先启用并保存发信设置。');
        $key = 'mail-test:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, '测试邮件发送频繁，请一分钟后再试。');
        RateLimiter::hit($key, 60);
        try {
            $mail->prepare();
            Mail::raw('这是一封 VisionSY 发信测试邮件。收到此邮件说明当前邮件服务已成功投递。', function ($message) use ($data) {
                $message->to($data['recipient'])->subject('VisionSY 邮件服务测试');
            });
        } catch (\Throwable $error) {
            $audit->log('mail.test_failed', $request->user(), context: ['error_type' => get_class($error)]);
            return response()->json(['message' => '发送失败。请检查服务器地址、端口、加密方式、发件地址及 SMTP 授权码。'], 422);
        }
        $audit->log('mail.test_sent', $request->user());
        return response()->json(['message' => '邮件服务器已接受测试邮件，请到收件箱或垃圾箱确认。']);
    }
}

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VisionSY 邮箱验证码</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border-radius:20px;border:1px solid #e6e9f2;overflow:hidden;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#3576f5,#7c5cff);padding:28px 32px;">
                            <span style="display:inline-block;width:36px;height:36px;line-height:36px;text-align:center;border-radius:10px;background:rgba(255,255,255,.18);color:#fff;font-weight:700;font-size:16px;">V</span>
                            <span style="color:#ffffff;font-size:17px;font-weight:600;margin-left:10px;vertical-align:middle;">VisionSY 校园媒体资产管理系统</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px;font-size:16px;color:#0c0f1a;font-weight:600;">你好，{{ $user->display_name }}</p>
                            <p style="margin:0 0 24px;font-size:14px;color:#5b6376;line-height:1.7;">
                                你正在验证 VisionSY 账号邮箱，请在验证页面输入以下验证码：
                            </p>
                            <div style="text-align:center;margin:0 0 24px;">
                                <span style="display:inline-block;padding:14px 28px;border-radius:14px;background:#eef3ff;border:1px solid #d7e3ff;color:#1d4fd7;font-size:30px;font-weight:700;letter-spacing:10px;font-family:'SF Mono',Menlo,Consolas,monospace;">{{ $code }}</span>
                            </div>
                            <p style="margin:0;font-size:13px;color:#8a91a3;line-height:1.7;">
                                验证码 {{ $ttlMinutes }} 分钟内有效，请勿泄露给他人。<br>
                                如果这不是你的操作，请忽略本邮件。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;border-top:1px solid #eef0f6;">
                            <p style="margin:0;font-size:12px;color:#a3a9ba;">© {{ date('Y') }} VisionSY · 学校宣传部 · 本邮件由系统自动发送，请勿直接回复。</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

/**
 * PlayCaptcha（github.com/mortspace/playcaptcha）登录页 React 岛。
 *
 * 上游组件 <ClawCaptcha> 是一台抓娃娃机：用摇杆（或方向键）移动爪子、
 * 按红色按钮抓取，把指定玩具投进舱口即验证通过（onVerify 回调）。
 * 上游定位是"确认有人在玩"的前置交互（其 README 原话：keep it in
 * front of your real checks）——因此服务端仍保留一层校验：
 *   1. 页面加载时 GET /captcha/challenge 领取一次性挑战 id；
 *   2. onVerify 触发后 POST /captcha/verify {id}，服务端核对
 *      挑战存在、未消费、且游玩耗时达到人类下限，签发一次性 pass token；
 *   3. token 写入表单隐藏域 #captcha_token，随登录一起提交并在服务端消费。
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ClawCaptcha } from 'playcaptcha';
import 'playcaptcha/clawcaptcha.css';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function CaptchaIsland() {
    const [phase, setPhase] = useState('loading'); // loading | playing | verified | error
    const [message, setMessage] = useState('');
    const challengeId = useRef(null);

    const loadChallenge = useCallback(async () => {
        setPhase('loading');
        setMessage('');
        try {
            const res = await fetch('/captcha/challenge', { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            challengeId.current = data.id;
            setPhase('playing');
        } catch {
            setPhase('error');
            setMessage('人机验证加载失败，请刷新页面重试。');
        }
    }, []);

    useEffect(() => { loadChallenge(); }, [loadChallenge]);

    const onVerify = useCallback(async () => {
        try {
            const res = await fetch('/captcha/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ id: challengeId.current }),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.ok) {
                const input = document.getElementById('captcha_token');
                if (input) input.value = data.token;
                window.dispatchEvent(new CustomEvent('claw:verified', { detail: { token: data.token } }));
                setPhase('verified');
                return;
            }

            setMessage(data.reason === 'too_fast'
                ? '完成得太快了，再玩一局吧。'
                : '验证已过期，已重新开始。');
            await loadChallenge();
        } catch {
            setMessage('网络异常，请重试。');
            await loadChallenge();
        }
    }, [loadChallenge]);

    if (phase === 'verified') {
        return (
            <div className="flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                人机验证已通过
            </div>
        );
    }

    return (
        <div>
            {phase === 'error'
                ? <p className="form-error">{message}</p>
                : <ClawCaptcha key={challengeId.current ?? 'boot'} title="抓到指定玩具，证明你是人类" onVerify={onVerify} assetBase={`${window.location.origin}/toys/`} />}
            {phase !== 'error' && message ? <p className="form-error">{message}</p> : null}
        </div>
    );
}

const rootEl = document.getElementById('claw-captcha-root');
if (rootEl) {
    createRoot(rootEl).render(<CaptchaIsland />);
}

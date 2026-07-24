/**
 * PlayCaptcha（github.com/mortspace/playcaptcha）登录页 React 岛。
 *
 * 行为模仿 Google reCAPTCHA v2：默认渲染一个 checkbox 按钮"点击进行人机验证"，
 * 用户点击后才发 GET /captcha/challenge 领取挑战、通过 createPortal 将抓娃娃机
 * 弹窗挂载到 document.body。验证通过后弹窗关闭、原位切换为绿色打勾。
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { createPortal } from 'react-dom';
import { ClawCaptcha } from 'playcaptcha';
import 'playcaptcha/clawcaptcha.css';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function CaptchaIsland() {
    const [phase, setPhase] = useState('idle'); // idle | loading | playing | verified
    const [modalOpen, setModalOpen] = useState(false);
    const [message, setMessage] = useState('');
    const challengeId = useRef(null);
    const triggerRef = useRef(null);
    const modalRef = useRef(null);
    const abortRef = useRef(null);

    const fetchChallenge = useCallback(async (signal) => {
        const res = await fetch('/captcha/challenge', {
            headers: { Accept: 'application/json' },
            signal,
        });
        if (!res.ok) throw new Error(String(res.status));
        const data = await res.json();
        challengeId.current = data.id;
    }, []);

    const handleOpen = useCallback(async () => {
        if (abortRef.current) abortRef.current.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setMessage('');
        setPhase('loading');
        setModalOpen(true);
        triggerRef.current = document.activeElement;

        try {
            await fetchChallenge(controller.signal);
            setPhase('playing');
        } catch (err) {
            if (err.name === 'AbortError') return;
            setPhase('idle');
            setModalOpen(false);
            setMessage('加载失败，请重试');
        }
    }, [fetchChallenge]);

    const retryChallenge = useCallback(async () => {
        if (abortRef.current) abortRef.current.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setPhase('loading');
        try {
            await fetchChallenge(controller.signal);
            setPhase('playing');
        } catch (err) {
            if (err.name === 'AbortError') return;
            setPhase('idle');
            setModalOpen(false);
            setMessage('加载失败，请重试');
        }
    }, [fetchChallenge]);

    const handleClose = useCallback(() => {
        if (abortRef.current) {
            abortRef.current.abort();
            abortRef.current = null;
        }
        setModalOpen(false);
        setPhase('idle');
        triggerRef.current?.focus();
    }, []);

    // 锁住背景页面滚动
    useEffect(() => {
        if (modalOpen) {
            const prev = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            return () => { document.body.style.overflow = prev; };
        }
    }, [modalOpen]);

    // 游戏加载完成后自动聚焦 .clawcap
    useEffect(() => {
        if (phase === 'playing' && modalOpen) {
            const el = document.querySelector('#claw-captcha-modal .clawcap');
            if (el) {
                el.focus({ preventScroll: true });
            } else {
                const timer = setTimeout(() => {
                    const el = document.querySelector('#claw-captcha-modal .clawcap');
                    if (el) el.focus({ preventScroll: true });
                }, 100);
                return () => clearTimeout(timer);
            }
        }
    }, [phase, modalOpen]);

    // 弹窗内 Tab 焦点陷阱
    const handleModalKeyDown = useCallback((e) => {
        if (e.key === 'Escape') {
            handleClose();
            return;
        }
        if (e.key === 'Tab' && modalRef.current) {
            const focusable = modalRef.current.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }, [handleClose]);

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
                setModalOpen(false);
                triggerRef.current?.focus();
                return;
            }

            setMessage(data.reason === 'too_fast'
                ? '完成得太快了，再玩一局吧。'
                : '验证已过期，已重新开始。');
            await retryChallenge();
        } catch {
            setMessage('网络异常，请重试。');
            await retryChallenge();
        }
    }, [retryChallenge]);

    if (phase === 'verified') {
        return (
            <div className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
                 role="checkbox" aria-checked="true">
                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                人机验证已通过
            </div>
        );
    }

    return (
        <div>
            <button type="button"
                    className="captcha-checkbox-btn"
                    role="checkbox"
                    aria-checked="false"
                    onClick={handleOpen}
                    disabled={phase === 'loading'}>
                <span className="captcha-checkbox-box" aria-hidden="true" />
                <span className="captcha-checkbox-label">点击进行人机验证</span>
            </button>
            {message && <p className="form-error">{message}</p>}

            {modalOpen && createPortal(
                <div id="claw-captcha-modal" className="captcha-modal-overlay" onClick={handleClose}>
                    <div className="captcha-modal-backdrop" aria-hidden="true" />
                    <div ref={modalRef}
                         role="dialog"
                         aria-modal="true"
                         aria-label="人机验证"
                         tabIndex={-1}
                         className="captcha-modal-card"
                         onClick={e => e.stopPropagation()}
                         onKeyDown={handleModalKeyDown}>
                        {phase === 'loading' && (
                            <div className="flex items-center justify-center py-10 text-sm text-ink-400">
                                抓娃娃机加载中…
                            </div>
                        )}
                        {phase === 'playing' && (
                            <>
                                {message && <p className="form-error mb-3">{message}</p>}
                                <ClawCaptcha
                                    key={challengeId.current ?? 'boot'}
                                    title="抓到指定玩具，证明你是人类"
                                    onVerify={onVerify}
                                    assetBase={`${window.location.origin}/toys/`}
                                />
                            </>
                        )}
                    </div>
                </div>,
                document.body
            )}
        </div>
    );
}

const rootEl = document.getElementById('claw-captcha-root');
if (rootEl) {
    createRoot(rootEl).render(<CaptchaIsland />);
}

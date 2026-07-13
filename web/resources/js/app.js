import Alpine from 'alpinejs';

/* ==========================================================================
   VisionSY 前端交互
   - heroCarousel：首页影像轮播（Alpine 组件）
   - initReveal：滚动显现（IntersectionObserver + 级联延迟）
   - initScrollProgress：顶部阅读进度条
   - initPointerFX：指针视差 / 聚光卡片 / 深色面光斑 / 磁性按钮
   全部效果尊重 prefers-reduced-motion；指针效果只在精确指针设备启用。
   ========================================================================== */

// 标记 JS 已就绪：reveal 的初始隐藏样式只在 html.js 下生效，
// 无 JS 环境（或脚本加载失败）内容照常可见。
document.documentElement.classList.add('js');

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const finePointer = window.matchMedia('(pointer: fine)').matches;

/* 液态玻璃边缘透镜（Apple Liquid Glass 复刻，方案参照 shuding/liquid-glass 与 kube.io 的拆解）：
   1. 离屏探针检测浏览器是否真正支持 backdrop-filter: url(...)（Firefox 不稳定直接排除，
      Safari 不支持，实际只有 Chromium 系通过）；
   2. 通过后按导航条实际宽高 + 圆角，用圆角矩形 SDF（有向距离场）逐像素生成位移贴图：
      中心区域零位移，边缘 BEZEL 带内把背后像素沿边缘法线向内拉（凸透镜放大观感），
      R 通道编码水平位移、G 通道编码垂直位移，canvas → dataURL 注入 #liquid-lens-map；
   3. 贴图注入完成后才给 <html> 加 .supports-refraction 启用滤镜（避免空贴图坏帧），
      尺寸变化由 ResizeObserver 触发重建。不支持的浏览器停留在纯毛玻璃回退。 */
(function initLiquidGlassLens() {
    if (typeof CSS === 'undefined' || typeof CSS.supports !== 'function') return;
    if (!CSS.supports('backdrop-filter', 'blur(1px)') &&
        !CSS.supports('-webkit-backdrop-filter', 'blur(1px)')) return;
    if (/firefox/i.test(navigator.userAgent)) return;
    const probe = document.createElement('div');
    probe.style.backdropFilter = 'url(#liquid-lens-probe)';
    probe.style.webkitBackdropFilter = 'url(#liquid-lens-probe)';
    if (!/url\(/.test(probe.style.backdropFilter) && !/url\(/.test(probe.style.webkitBackdropFilter)) return;

    const run = () => {
        const feImage = document.getElementById('liquid-lens-map');
        // 色散：RGB 三通道位移器，强度依次递减（红光折射最弱→红边在外，模拟真实玻璃色散）
        const feMaps = [
            [document.getElementById('liquid-lens-dr'), 1.00],
            [document.getElementById('liquid-lens-dg'), 0.93],
            [document.getElementById('liquid-lens-db'), 0.86],
        ];
        const bar = document.querySelector('.nav-bar.liquid-glass-dark');
        if (!feImage || feMaps.some(([el]) => !el) || !bar) return;

        const BEZEL = 14;      // 边缘折射带宽度（px），带外的中心区域零畸变
        const MAX_SHIFT = 22;  // 边缘最大位移量（px），决定"透镜弯折"的强度

        const smoothStep = (a, b, t) => {
            t = Math.min(Math.max((t - a) / (b - a), 0), 1);
            return t * t * (3 - 2 * t);
        };
        // 圆角矩形有向距离场：内部为负、边缘为 0、外部为正
        const sdf = (x, y, hw, hh, r) => {
            const qx = Math.abs(x) - hw + r;
            const qy = Math.abs(y) - hh + r;
            return Math.min(Math.max(qx, qy), 0) + Math.hypot(Math.max(qx, 0), Math.max(qy, 0)) - r;
        };

        const buildMap = (w, h, radius) => {
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            const img = ctx.createImageData(w, h);
            const data = img.data;
            const hw = w / 2, hh = h / 2;
            const r = Math.min(radius, hw, hh);
            for (let py = 0; py < h; py++) {
                for (let px = 0; px < w; px++) {
                    const x = px + 0.5 - hw;
                    const y = py + 0.5 - hh;
                    const d = sdf(x, y, hw, hh, r);
                    // 数值梯度 ≈ 边缘外法线方向
                    const gx = sdf(x + 1, y, hw, hh, r) - sdf(x - 1, y, hw, hh, r);
                    const gy = sdf(x, y + 1, hw, hh, r) - sdf(x, y - 1, hw, hh, r);
                    const glen = Math.hypot(gx, gy) || 1;
                    // 距边缘 BEZEL 以内平滑升到 1，二次衰减保证中心大面积完全无畸变
                    const t = smoothStep(-BEZEL, -0.5, d);
                    const shift = t * t * MAX_SHIFT;
                    // 沿法线向内采样 → 中心内容被"放大"挤到边缘，即凸透镜观感
                    const dx = -(gx / glen) * shift;
                    const dy = -(gy / glen) * shift;
                    const i = (py * w + px) * 4;
                    data[i]     = Math.round((dx / (MAX_SHIFT * 2) + 0.5) * 255);
                    data[i + 1] = Math.round((dy / (MAX_SHIFT * 2) + 0.5) * 255);
                    data[i + 2] = 128;
                    data[i + 3] = 255;
                }
            }
            ctx.putImageData(img, 0, 0);
            return canvas.toDataURL();
        };

        let raf = 0;
        const refresh = () => {
            const rect = bar.getBoundingClientRect();
            const w = Math.max(2, Math.round(rect.width));
            const h = Math.max(2, Math.round(rect.height));
            const radius = parseFloat(getComputedStyle(bar).borderTopLeftRadius) || 24;
            feImage.setAttribute('href', buildMap(w, h, radius));
            feMaps.forEach(([el, k]) => el.setAttribute('scale', String(MAX_SHIFT * 2 * k)));
            document.documentElement.classList.add('supports-refraction');
        };
        refresh();
        new ResizeObserver(() => {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(refresh);
        }).observe(bar);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();

/* ================= Hero 影像轮播（背景图淡入淡出状态机）=================
   仅驱动背景图层的自动轮播（active 切换 → 模板内 :class 控制 opacity/scale）。
   第 3 轮任务 10：原流体弹性分页器 UI（高亮缓存/弹性缓动/数字按钮联动等逻辑）
   已随分页器 DOM 一并删除，组件仅保留 start/stop/go/next/prev 状态机与定时器。 */
Alpine.data('heroCarousel', (count = 1, interval = 6000) => ({
    active: 0,
    count,
    interval,
    timer: null,

    init() {
        if (!reduceMotion && this.count > 1) {
            this.start();
            document.addEventListener('visibilitychange', () => {
                document.hidden ? this.stop() : this.start();
            });
        }
    },
    start() {
        this.stop();
        this.timer = setInterval(() => this.next(), this.interval);
    },
    stop() {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
    },
    go(i) {
        this.active = (i + this.count) % this.count;
        if (this.timer) this.start();
    },
    next() { this.go(this.active + 1); },
    prev() { this.go(this.active - 1); },
}));

/* ---------------- 滚动显现 ---------------- */
function initReveal() {
    const items = document.querySelectorAll('[data-reveal]');
    if (!items.length) return;

    if (reduceMotion || !('IntersectionObserver' in window)) {
        items.forEach((el) => el.classList.add('is-revealed'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-revealed');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

    items.forEach((el) => {
        // data-reveal-delay="120" -> 延迟 120ms；
        // 容器可用 data-reveal-stagger 给子元素自动级联。
        const delay = el.dataset.revealDelay;
        if (delay) el.style.setProperty('--reveal-delay', `${delay}ms`);
        observer.observe(el);
    });

    document.querySelectorAll('[data-reveal-stagger]').forEach((group) => {
        const step = Number(group.dataset.revealStagger) || 90;
        group.querySelectorAll(':scope [data-reveal]').forEach((el, i) => {
            if (!el.dataset.revealDelay) {
                el.style.setProperty('--reveal-delay', `${i * step}ms`);
            }
        });
    });
}

/* ---------------- 顶部阅读进度条 ---------------- */
function initScrollProgress() {
    const bar = document.getElementById('scroll-progress');
    if (!bar) return;
    let ticking = false;
    const update = () => {
        const max = document.documentElement.scrollHeight - window.innerHeight;
        const p = max > 0 ? Math.min(window.scrollY / max, 1) : 0;
        bar.style.setProperty('--scroll-progress', p.toFixed(4));
        ticking = false;
    };
    window.addEventListener('scroll', () => {
        if (!ticking) { requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    update();
}

/* ---------------- 指针效果 ---------------- */
function initPointerFX() {
    if (reduceMotion || !finePointer) return;

    /* 1) 指针视差：容器 [data-parallax-scene]，子层 [data-parallax="深度"]。
          指针离中心越远，各层按深度轻微位移，形成取景器般的纵深。 */
    document.querySelectorAll('[data-parallax-scene]').forEach((scene) => {
        const layers = scene.querySelectorAll('[data-parallax]');
        if (!layers.length) return;
        let raf = null;
        let tx = 0, ty = 0;
        const render = () => {
            layers.forEach((layer) => {
                const depth = parseFloat(layer.dataset.parallax) || 8;
                layer.style.transform = `translate3d(${tx * depth}px, ${ty * depth}px, 0)`;
            });
            raf = null;
        };
        scene.addEventListener('pointermove', (e) => {
            const r = scene.getBoundingClientRect();
            tx = ((e.clientX - r.left) / r.width - 0.5);
            ty = ((e.clientY - r.top) / r.height - 0.5);
            if (!raf) raf = requestAnimationFrame(render);
        });
        scene.addEventListener('pointerleave', () => {
            tx = 0; ty = 0;
            if (!raf) raf = requestAnimationFrame(render);
        });
    });

    /* 2) 聚光卡片 / 液态玻璃流光：写入指针坐标 --sx / --sy
          导航栏（.liquid-glass-dark）已按 Apple 材质移除指针流光 ::after（Apple 玻璃无光标跟随高光），
          选择器不再包含它。 */
    document.addEventListener('pointermove', (e) => {
        const card = e.target.closest?.('.card-spot, .liquid-glass, .liquid-glass-light');
        if (!card) return;
        const r = card.getBoundingClientRect();
        card.style.setProperty('--sx', `${((e.clientX - r.left) / r.width) * 100}%`);
        card.style.setProperty('--sy', `${((e.clientY - r.top) / r.height) * 100}%`);
    }, { passive: true });

    /* 3) 深色面跟随光斑：.glow-scene 内更新 --gx / --gy */
    document.querySelectorAll('.glow-scene').forEach((scene) => {
        scene.addEventListener('pointermove', (e) => {
            const r = scene.getBoundingClientRect();
            scene.style.setProperty('--gx', `${((e.clientX - r.left) / r.width) * 100}%`);
            scene.style.setProperty('--gy', `${((e.clientY - r.top) / r.height) * 100}%`);
            scene.classList.add('is-glowing');
        }, { passive: true });
        scene.addEventListener('pointerleave', () => scene.classList.remove('is-glowing'));
    });

    /* 4) 磁性按钮：[data-magnetic] 在指针接近时轻微吸附 */
    document.querySelectorAll('[data-magnetic]').forEach((el) => {
        const strength = parseFloat(el.dataset.magnetic) || 0.22;
        el.style.transition = 'transform 0.25s cubic-bezier(0.22, 1, 0.36, 1)';
        el.addEventListener('pointermove', (e) => {
            const r = el.getBoundingClientRect();
            const dx = e.clientX - (r.left + r.width / 2);
            const dy = e.clientY - (r.top + r.height / 2);
            el.style.transform = `translate(${dx * strength}px, ${dy * strength}px)`;
        });
        el.addEventListener('pointerleave', () => {
            el.style.transform = '';
        });
    });
}

/* ---------------- 导航栏背景感知自适应亮暗（任务2）----------------
   按"导航栏正下方那一小条像素"背后 section 的深浅，给 <header> 打 nav-tone-{dark|light}，
   CSS 据此切换 .nav-bar.liquid-glass-dark 的玻璃配色与文字颜色（见 app.css）。
   用 elementFromPoint 点采样 + rAF 节流 scroll，比 IntersectionObserver 的整体可见性判断
   更贴合"导航栏此刻背后是谁"的语义。section 通过 data-nav-tone 属性声明深浅。
   无标注页面（如 dashboard/admin）默认按深色处理，维持原深色玻璃观感，
   并由 .liquid-glass-dark 兜底对比度保证可读。 */
function initNavToneAwareness() {
    const header = document.querySelector('header');
    if (!header) return;
    let ticking = false;
    let currentTone = null;
    const update = () => {
        const rect = header.getBoundingClientRect();
        // 采样导航栏底部正下方一点（避开 header 自身的命中区）
        const x = Math.max(1, Math.min(rect.left + rect.width / 2, document.documentElement.clientWidth - 1));
        const y = rect.bottom + 2;
        const el = document.elementFromPoint(x, y);
        const tagged = el && el.closest ? el.closest('[data-nav-tone]') : null;
        const tone = (tagged && tagged.dataset.navTone) || 'dark';
        if (tone !== currentTone) {
            currentTone = tone;
            header.classList.remove('nav-tone-dark', 'nav-tone-light');
            header.classList.add('nav-tone-' + tone);
        }
        ticking = false;
    };
    const onScroll = () => {
        if (!ticking) { requestAnimationFrame(update); ticking = true; }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    update();
}

document.addEventListener('DOMContentLoaded', () => {
    initReveal();
    initScrollProgress();
    initPointerFX();
    initNavToneAwareness();
});

window.Alpine = Alpine;
Alpine.start();

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/claw-captcha.jsx', // PlayCaptcha（React 岛，仅登录页加载）
            ],
            // 开发时保存以下路径中的文件会触发浏览器整页刷新，
            // 配合 `npm run dev` 即可获得 Live Server 式体验，无需 build。
            refresh: [
                'resources/views/**',
                'routes/**',
                'app/Http/Controllers/**',
                'app/View/Components/**',
                'app/Services/**',
                'lang/**',
                'resources/lang/**',
            ],
        }),
        react(),
    ],
    // PlayCaptcha 上游组件以绝对路径请求 /playcaptcha.svg 与 /toys/*.png。
    // 生产环境由 Laravel 直接从 public/ 提供；开发环境（npm run dev）下
    // 这些静态资源位于 Laravel 进程的 :8000（public/），所以把 Vite dev
    // server 上对 /playcaptcha.svg、/toys 的请求代理过去，效果等同于
    // hst 2 的 express.static('public') 同源托管。
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // PlayCaptcha assets proxy: use Docker service name for cross-container access.
        // The Vite container is on the visionsy network; app:80 is the FrankenPHP service.
        proxy: {
            '/playcaptcha.svg': 'http://app:80',
            '/toys': 'http://app:80',
        },
        watch: {
            // Windows Docker bind mount 不支持文件事件，使用轮询。
            // 默认 100ms 轮询间隔对 CPU 压力过大；改用 2s 间隔，仍可在数秒内感知变更。
            usePolling: true,
            interval: 2000,
        },
    },
});

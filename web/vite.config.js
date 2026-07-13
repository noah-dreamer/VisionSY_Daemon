import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
    ],
});

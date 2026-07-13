/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    '"Inter"', '"PingFang SC"', '"Hiragino Sans GB"',
                    '"Microsoft YaHei"', 'system-ui', 'sans-serif',
                ],
                // 展示级衬线：大标题用宋体系，带一点校刊 / 画册的手感
                display: [
                    '"Noto Serif SC"', '"Songti SC"', '"STSong"',
                    '"SimSun"', '"Source Han Serif SC"', 'serif',
                ],
                // 元数据等宽字：取景器 / EXIF 式标注
                meta: [
                    'ui-monospace', '"SF Mono"', '"JetBrains Mono"',
                    'Menlo', 'Consolas', 'monospace',
                ],
            },
            colors: {
                // 品牌蓝 —— 主色
                brand: {
                    50: '#eef5ff',
                    100: '#d9e8ff',
                    200: '#bcd6ff',
                    300: '#8ebcff',
                    400: '#5b98ff',
                    500: '#3576f5',
                    600: '#1f59e6',
                    700: '#1845c7',
                    800: '#193ba0',
                    900: '#1a377e',
                    950: '#14224e',
                },
                // 紫色点缀 —— 仅用于细微渐变与高光
                accent: {
                    400: '#9d7bff',
                    500: '#7c5cff',
                    600: '#6940f0',
                },
                // REC 红 —— 只做极小面积的信号色（录制点、悬停强调）
                signal: {
                    500: '#e5484d',
                },
                // 中性墨色 —— 文本、边框、深色面
                ink: {
                    50: '#f7f8fa',
                    100: '#eceef3',
                    200: '#dce0e8',
                    300: '#b9c0cf',
                    400: '#8b94a8',
                    500: '#646e85',
                    600: '#4d566c',
                    700: '#3c4356',
                    800: '#2b3041',
                    900: '#1a1e2b',
                    950: '#0c0f1a',
                },
            },
            maxWidth: {
                shell: '76rem',
            },
            borderRadius: {
                '4xl': '2rem',
            },
            boxShadow: {
                soft: '0 1px 2px rgb(12 15 26 / 0.04), 0 6px 20px -8px rgb(12 15 26 / 0.10)',
                card: '0 1px 2px rgb(12 15 26 / 0.05), 0 10px 30px -12px rgb(12 15 26 / 0.14)',
                lift: '0 1px 2px rgb(12 15 26 / 0.06), 0 18px 50px -16px rgb(12 15 26 / 0.22)',
                glow: '0 0 0 1px rgb(53 118 245 / 0.10), 0 10px 36px -12px rgb(31 89 230 / 0.45)',
                'inner-line': 'inset 0 0 0 1px rgb(255 255 255 / 0.08)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(12px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                marquee: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
                'rec-pulse': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.25' },
                },
                'slow-zoom': {
                    '0%': { transform: 'scale(1)' },
                    '100%': { transform: 'scale(1.06)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.6s cubic-bezier(0.22, 1, 0.36, 1) both',
                'fade-in': 'fade-in 0.5s ease both',
                marquee: 'marquee 120s linear infinite',
                'rec-pulse': 'rec-pulse 1.6s ease-in-out infinite',
                'slow-zoom': 'slow-zoom 9s ease-out both',
            },
        },
    },
    plugins: [],
};

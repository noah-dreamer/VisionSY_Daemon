@php
    // dev 模式（public/hot 存在）下，@vitejs/plugin-react 要求在每个 React
    // 模块执行前先注入 React Refresh 前导码（设置 window.$RefreshReg$ /
    // $RefreshSig$），否则 jsx 顶部的守卫会抛
    // "@vitejs/plugin-react can't detect preamble. Something is wrong."
    // 导致 React 岛（如登录页 ClawCaptcha）永远挂载不上、停留在占位文字。
    //
    // 纯 Vite SPA（如 hst 2）由 Vite 直接出 HTML，前导码经 transformIndexHtml
    // 自动注入；laravel-vite-plugin 由 Laravel 出 Blade HTML、绕过该钩子，
    // 故此前导码不会自动产生，这里手动补一份。生产构建关闭 React Refresh，
    // 无此守卫，因此仅 dev 输出。
    $hotPath = public_path('hot');
    $devUrl = is_file($hotPath) ? rtrim(trim((string) file_get_contents($hotPath)), '/') : null;
    $refreshUrl = $devUrl !== null ? $devUrl . '/@react-refresh' : null;
@endphp
@if($refreshUrl)
    <script type="module">
        import RefreshRuntime from "{{ $refreshUrl }}"
        RefreshRuntime.injectIntoGlobalHook(window)
        window.$RefreshReg$ = () => {}
        window.$RefreshSig$ = () => (type) => type
        window.__vite_plugin_react_preamble_installed__ = true
    </script>
@endif

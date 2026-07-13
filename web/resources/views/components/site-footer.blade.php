<footer class="border-t border-ink-100 bg-white">
    <div class="shell flex flex-col gap-6 py-10 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-col gap-3">
            <x-brand sub="校园媒体资产管理" />
            <p class="max-w-sm text-sm leading-relaxed text-ink-500">
                学校宣传部自托管媒体资产平台 · 统一归档、分级取用、校园网激活。
            </p>
        </div>
        <nav class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-ink-500" aria-label="页脚导航">
            <a href="https://example.com" class="transition hover:text-ink-900">example.com</a>
            <a href="{{ route('media.index') }}" class="transition hover:text-ink-900">媒体库</a>
            @auth
                <a href="{{ config('visionsy.urls.auth') }}/dashboard" class="transition hover:text-ink-900">控制台</a>
            @else
                <a href="{{ config('visionsy.urls.auth') }}/login" class="transition hover:text-ink-900">登录</a>
            @endauth
        </nav>
    </div>
    <div class="border-t border-ink-100">
        <div class="shell flex flex-col gap-3 py-5 text-xs text-ink-400 sm:flex-row sm:items-center sm:justify-between">
            <p class="flex items-center gap-2.5">
                {{-- VisionSY 项目 logo 占位：后续替换为正式 logo，见 components/brand.blade.php 顶部注释 --}}
                <span class="brand-mark h-6 w-6 text-[11px]" aria-hidden="true">V</span>
                <span>© {{ date('Y') }} VisionSY · 学校宣传部 校园媒体资产管理系统</span>
            </p>
            <p class="flex items-center">
                {{-- 主办方：湖北省武昌实验中学。
                     原始 ssyzx.png 整体色调极浅，直接放白色页脚上几乎不可读；
                     此处引用经图像级反相/调深处理后的 ssyzx-dark.png（architecture.md 6.3 已确认方案 B）。
                     原始浅色版 ssyzx.png 保留在 public/images/brand/ 作为源图备份。 --}}
                <img src="{{ asset('images/brand/ssyzx-dark.png') }}"
                     alt="湖北省武昌实验中学 · HuBei Wuchang Experimental High School"
                     class="h-6 w-auto opacity-80" loading="lazy">
            </p>
            <p class="font-meta uppercase tracking-[0.18em]">VSSY · Student Media Center</p>
        </div>
    </div>
</footer>

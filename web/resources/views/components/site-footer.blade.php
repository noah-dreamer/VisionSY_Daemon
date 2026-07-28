<footer class="border-t border-ink-100 bg-white">
    <div class="shell flex flex-col gap-3 py-5 text-xs text-ink-400 sm:flex-row sm:items-center sm:justify-between">
        <p class="flex items-center gap-2.5">
            <img src="{{ asset('images/brand/logo.png') }}" class="h-6 w-6 object-contain" alt="VisionSY">
            <span>© {{ date('Y') }} VisionSY · 学校宣传部 校园媒体资产管理系统</span>
        </p>
        <p class="flex items-center">
            {{-- 原始 ssyzx.png 颜色极浅，此处引用反相/调深处理后的 ssyzx-dark.png。
                 浅色原版 ssyzx.png 保留在 public/images/brand/ 作为源图。 --}}
            <img src="{{ asset('images/brand/ssyzx-dark.png') }}"
                 alt="湖北省武昌实验中学 · HuBei Wuchang Experimental High School"
                 class="h-6 w-auto opacity-80" loading="lazy">
        </p>
        <p class="font-meta uppercase tracking-[0.18em]">VSSY · Student Media Center</p>
    </div>
</footer>

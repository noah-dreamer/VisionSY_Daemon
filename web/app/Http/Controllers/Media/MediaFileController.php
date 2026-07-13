<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaResource;
use App\Services\AuditLogger;
use App\Services\Media\MediaStorageService;
use App\Services\RedirectDecisionService;
use App\Services\TransferTokenService;
use App\Services\TrustedProxyIpResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * 媒体文件出口。
 *
 * 下载分两种工作模式（config: visionsy.media.direct_serve）：
 *   - direct_serve = true ：本机直接流式返回（本地开发 / 单机部署）；
 *   - direct_serve = false：按 README「流量分流机制」返回 HTTP 307，
 *       重定向到校内/校外直连域名（携带一次性 TransferToken），
 *       浏览器绕过 CDN 与公网 VPS 直连学校服务器，由 /media/transfer 出口凭 token 放行。
 */
class MediaFileController extends Controller
{
    public function __construct(
        private MediaStorageService $storage,
        private AuditLogger $audit,
    ) {
    }

    /** 缩略图（带水印，列表用；游客视配置可见）。 */
    public function thumb(MediaResource $resource): Response
    {
        Gate::authorize('view', $resource);

        if (! $resource->has_thumb) {
            abort(404);
        }

        return $this->streamInline($resource->thumbRelativePath(), 'image/jpeg');
    }

    /** 带水印预览大图（详情页，需登录）。 */
    public function preview(MediaResource $resource): Response
    {
        Gate::authorize('preview', $resource);

        if (! $resource->has_preview) {
            abort(404);
        }

        return $this->streamInline($resource->previewRelativePath(), 'image/jpeg');
    }

    /** 下载带水印压缩图（普通用户可用）。 */
    public function downloadWatermarked(Request $request, MediaResource $resource): Response
    {
        Gate::authorize('downloadWatermarked', $resource);

        if (! $resource->has_preview) {
            abort(404, '该素材没有可下载的压缩图。');
        }

        return $this->deliver($request, $resource, 'watermarked');
    }

    /** 下载无水印原图（宣传部 / 官微 / 管理员）。 */
    public function downloadOriginal(Request $request, MediaResource $resource): Response
    {
        Gate::authorize('downloadOriginal', $resource);

        return $this->deliver($request, $resource, 'original');
    }

    /**
     * 307 直连出口：无会话，凭一次性 TransferToken 放行。
     * 生产环境该路由经 ssy/lan-ssy 直连域名到达（绕过 CDN 与 VPS）。
     */
    public function transfer(Request $request, MediaResource $resource, TransferTokenService $tokens): Response
    {
        $result = $tokens->consume(
            plainToken: (string) $request->query('token', ''),
            action: TransferTokenService::ACTION_DOWNLOAD,
        );

        if (! $result['ok'] || $result['record']->resource_id !== "media:{$resource->id}") {
            abort(403, '直连令牌无效或已过期。');
        }

        $variant = $result['record']->file_path_hash === 'original' ? 'original' : 'watermarked';

        return $this->sendFile($resource, $variant);
    }

    /* ---------------------------------------------------------------- */

    /**
     * 用户组高级限制（对齐 ResourceSpace 高级组选项）：
     * - Resource download limit：每日下载限额；
     * - IP address restriction：仅允许指定网段下载。
     * 系统管理员不受限。
     */
    private function enforceGroupLimits(Request $request): void
    {
        $user = $request->user();
        $group = $user?->userGroup;

        if ($user === null || $group === null || $user->isSystemAdmin()) {
            return;
        }

        $ip = app(TrustedProxyIpResolver::class)->resolve($request);
        if (! $group->ipAllowed($ip)) {
            abort(403, "所在用户组「{$group->name}」限制了下载来源 IP。");
        }

        $remaining = $group->remainingDownloadsToday($user);
        if ($remaining !== null && $remaining <= 0) {
            abort(429, "已达到用户组「{$group->name}」的每日下载限额（{$group->download_limit_per_day} 次）。");
        }
    }

    private function deliver(Request $request, MediaResource $resource, string $variant): Response
    {
        $this->enforceGroupLimits($request);

        if (config('visionsy.media.direct_serve', true)) {
            $this->countDownload($request, $resource, $variant);

            return $this->sendFile($resource, $variant);
        }

        // 307 分流：校园 IP → 局域网直连域名；校外 IP → 学校公网直连域名
        $ipResolver = app(TrustedProxyIpResolver::class);
        $decision = app(RedirectDecisionService::class)->decide($ipResolver->resolve($request));

        $token = app(TransferTokenService::class)->generate(
            user: $request->user(),
            action: TransferTokenService::ACTION_DOWNLOAD,
            allowedHost: $decision['target_host'],
            originalMethod: 'GET',
            resourceId: "media:{$resource->id}",
            filePathHash: $variant,
            clientIp: $ipResolver->resolve($request),
        );

        $this->countDownload($request, $resource, $variant);

        $url = rtrim($decision['target_base_url'], '/')
            ."/media/{$resource->id}/transfer?token={$token['token']}";

        return redirect()->away($url, 307);
    }

    private function sendFile(MediaResource $resource, string $variant): Response
    {
        $disk = $this->storage->disk();

        if ($variant === 'original') {
            $path = $resource->originalRelativePath();
            $downloadName = $resource->original_name;
            $mime = $resource->mime;
        } else {
            $path = $resource->previewRelativePath();
            $base = pathinfo($resource->original_name, PATHINFO_FILENAME);
            $downloadName = "{$base}_watermarked.jpg";
            $mime = 'image/jpeg';
        }

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $downloadName, ['Content-Type' => $mime]);
    }

    private function streamInline(string $relativePath, string $mime): Response
    {
        $disk = $this->storage->disk();
        abort_unless($disk->exists($relativePath), 404);

        return $disk->response($relativePath, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function countDownload(Request $request, MediaResource $resource, string $variant): void
    {
        $column = $variant === 'original' ? 'download_count_original' : 'download_count_watermarked';
        $resource->increment($column);

        $this->audit->log("media.download_{$variant}", $request->user(), 'media_resource', (string) $resource->id, [
            'title' => $resource->title,
        ]);
    }
}

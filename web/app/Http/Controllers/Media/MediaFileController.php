<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaResource;
use App\Services\AuditLogger;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\MediaStorageService;
use App\Services\Media\PathResolverService;
use App\Services\RedirectDecisionService;
use App\Services\TransferTokenService;
use App\Services\TrustedProxyIpResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MediaFileController extends Controller
{
    public function __construct(
        private MediaStorageService $storage,
        private AuditLogger $audit,
        private PathResolverService $pathResolver,
    ) {
    }

    public function thumb(MediaResource $resource): Response
    {
        Gate::authorize('view', $resource);

        if (! $resource->has_thumb) {
            abort(404);
        }

        if ($resource->isLegacy()) {
            return $this->streamInlineFromDisk(
                $this->storage->disk(),
                $resource->thumbRelativePath(),
                'image/jpeg'
            );
        }

        $path = $this->pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        if (! is_file($path)) {
            abort(404);
        }

        return $this->streamInlineFromPath($path, 'image/jpeg');
    }

    public function preview(MediaResource $resource): Response
    {
        Gate::authorize('preview', $resource);

        if (! $resource->has_preview) {
            abort(404);
        }

        if ($resource->isLegacy()) {
            return $this->streamInlineFromDisk(
                $this->storage->disk(),
                $resource->previewRelativePath(),
                'image/jpeg'
            );
        }

        $path = $this->pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large);
        if (! is_file($path)) {
            abort(404);
        }

        return $this->streamInlineFromPath($path, 'image/jpeg');
    }

    public function downloadWatermarked(Request $request, MediaResource $resource): Response
    {
        Gate::authorize('downloadWatermarked', $resource);

        if (! $resource->has_preview) {
            abort(404, '该素材没有可下载的压缩图。');
        }

        return $this->deliver($request, $resource, 'watermarked');
    }

    public function downloadWatermarkedPng(Request $request, MediaResource $resource): Response
    {
        Gate::authorize('downloadWatermarked', $resource);

        if (! $resource->has_preview) {
            abort(404, '该素材没有可下载的压缩图。');
        }

        return $this->deliver($request, $resource, 'watermarked_png');
    }

    public function downloadOriginal(Request $request, MediaResource $resource): Response
    {
        Gate::authorize('downloadOriginal', $resource);

        return $this->deliver($request, $resource, 'original');
    }

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
        if ($variant === 'original') {
        if ($variant === 'watermarked_png') {
            $absPath = $this->pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedPng);
            if (! is_file($absPath)) {
                abort(404, 'PNG 水印图不可用。');
            }
            $base = pathinfo($resource->original_name, PATHINFO_FILENAME);

            return response()->download($absPath, "{$base}_watermarked.png", [
                'Content-Type' => 'image/png',
            ]);
        }

        if ($resource->isLegacy()) {
                $disk = $this->storage->disk();
                $path = $resource->originalRelativePath();
                abort_unless($disk->exists($path), 404);

                return $disk->download($path, $resource->original_name, ['Content-Type' => $resource->mime ?? 'application/octet-stream']);
            }

            $absPath = $this->pathResolver->resolveOriginalAbsolute($resource);
            if (! is_file($absPath)) {
                abort(404);
            }

            return response()->download($absPath, $resource->original_name, [
                'Content-Type' => $resource->mime ?? 'application/octet-stream',
            ]);
        }

        if ($resource->isLegacy()) {
            $disk = $this->storage->disk();
            $path = $resource->previewRelativePath();
            $base = pathinfo($resource->original_name, PATHINFO_FILENAME);
            $downloadName = "{$base}_watermarked.jpg";
            abort_unless($disk->exists($path), 404);

            return $disk->download($path, $downloadName, ['Content-Type' => 'image/jpeg']);
        }

        $absPath = $this->pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedJpeg);
        if (! is_file($absPath)) {
            $absPath = $this->pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large);
            if (! is_file($absPath)) {
                abort(404);
            }
        }

        $base = pathinfo($resource->original_name, PATHINFO_FILENAME);

        return response()->download($absPath, "{$base}_watermarked.jpg", [
            'Content-Type' => 'image/jpeg',
        ]);
    }

    private function streamInlineFromDisk($disk, string $relativePath, string $mime): Response
    {
        abort_unless($disk->exists($relativePath), 404);

        return $disk->response($relativePath, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function streamInlineFromPath(string $absolutePath, string $mime): Response
    {
        return response()->file($absolutePath, [
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

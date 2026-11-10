<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CORS is scoped to management; media-site cookies are never used by the dashboard. */
class ManagementCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/management/v1', 'api/management/v1/*')) {
            return $next($request);
        }
        $origin = $request->header('Origin');
        if (! $origin) {
            return $next($request); // CLI and same-origin requests still require a connection key.
        }
        $origins = array_filter(array_map('trim', explode(',', (string) config('management.allowed_origins'))));
        $sameOrigin = $request->getSchemeAndHttpHost();
        if ($origin === 'null' || ($origin !== $sameOrigin && ! in_array($origin, $origins, true))) {
            return response()->json(['message' => '此 Dashboard 来源未获允许，请在 daemon 配置 MANAGEMENT_ALLOWED_ORIGINS。'], 403)
                ->header('Vary', 'Origin')->header('Cache-Control', 'no-store');
        }
        if ($request->isMethod('OPTIONS')) {
            $headers = array_filter(array_map('trim', explode(',', strtolower((string) $request->header('Access-Control-Request-Headers')))));
            if (! in_array(strtoupper((string) $request->header('Access-Control-Request-Method')), ['GET', 'POST', 'PUT', 'DELETE'], true)
                || array_diff($headers, ['authorization', 'content-type', 'accept'])) {
                $response = response()->json(['message' => '不支持的跨域请求。'], 403);
            } else {
                $response = response('', 204);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
                $response->headers->set('Access-Control-Max-Age', '600');
                if ($request->header('Access-Control-Request-Private-Network') === 'true') {
                    $response->headers->set('Access-Control-Allow-Private-Network', 'true');
                }
            }
        } else {
            $response = $next($request);
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
        $response->setVary('Origin', false);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}

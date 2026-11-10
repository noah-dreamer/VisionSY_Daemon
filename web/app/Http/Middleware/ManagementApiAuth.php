<?php
namespace App\Http\Middleware;

use App\Models\ManagementApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ManagementApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $token = (string) $request->bearerToken();
        abort_unless(preg_match('/^vsy_([a-f0-9]{12})\.([a-f0-9]{64})$/D', $token, $parts), 401, '管理 API 密钥无效。');
        $key = ManagementApiKey::with('user')->where('prefix', $parts[1])->first();
        abort_unless($key && ! $key->revoked_at && hash_equals($key->secret_hash, hash('sha256', $parts[2])), 401, '管理 API 密钥无效或已撤销。');
        abort_unless($key->user?->isSystemAdmin() && $key->user->isActive(), 403, '密钥所属管理员已停用或权限不足。');
        Auth::setUser($key->user);
        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('management_key', $key);
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinutes(5))) {
            $key->update(['last_used_at' => now()]);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}

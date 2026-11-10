<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LegacyAdminAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('management.legacy_admin_enabled')) {
            return response()->view('admin.moved', ['dashboardUrl' => config('management.dashboard_url')], 410);
        }
        return $next($request);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\AccountActivationService;
use App\Services\CampusIpService;
use App\Services\TrustedProxyIpResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** 登录后的用户首页：按状态显示提示与「进入媒体库」入口。 */
    public function show(
        Request $request,
        TrustedProxyIpResolver $ipResolver,
        CampusIpService $campusIpService,
    ): View {
        $clientIp = $ipResolver->resolve($request);

        return view('dashboard', [
            'user' => $request->user(),
            'clientIp' => $clientIp,
            'isCampusIp' => $campusIpService->isCampusIp($clientIp),
            'resourceSpaceUrl' => config('visionsy.urls.resourcespace'),
        ]);
    }

    /** 待激活用户主动点击「重新检测当前网络」。 */
    public function recheckActivation(
        Request $request,
        AccountActivationService $activation,
    ): RedirectResponse {
        $activated = $activation->attemptActivation($request->user(), $request);

        return redirect()->route('dashboard')->with(
            'status',
            $activated ? '激活成功！现在可以进入媒体库了。' : '当前网络不属于校园网，请连接校园网后重试。'
        );
    }
}

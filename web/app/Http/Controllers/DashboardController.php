<?php

namespace App\Http\Controllers;

use App\Services\AccountActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $downloadRemaining = null;
        $downloadLimit = null;
        $downloadUnlimited = true;

        if (! $user->isSystemAdmin() && $user->userGroup) {
            $downloadRemaining = $user->userGroup->remainingDownloadsToday($user);
            $downloadLimit = $user->userGroup->download_limit_per_day;
            $downloadUnlimited = $downloadRemaining === null;
        }

        return view('dashboard', [
            'user' => $user,
            'intranetProbeUrl' => config('visionsy.intranet_probe_url'),
            'mediaLibraryUrl' => route('media.index'),
            'downloadRemaining' => $downloadRemaining,
            'downloadLimit' => $downloadLimit,
            'downloadUnlimited' => $downloadUnlimited,
        ]);
    }

    public function recheckActivation(
        Request $request,
        AccountActivationService $activation,
    ): RedirectResponse {
        $isCampus = $request->boolean('is_campus');
        $activated = $activation->attemptActivation($request->user(), $request, $isCampus);

        return redirect()->route('dashboard')->with(
            'status',
            $activated ? '激活成功！现在可以进入媒体库了。' : '当前不在校园网环境，请连接校园网后重试。'
        );
    }
}

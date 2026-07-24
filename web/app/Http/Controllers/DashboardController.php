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
        return view('dashboard', [
            'user' => $request->user(),
            'intranetProbeUrl' => config('visionsy.intranet_probe_url'),
            'mediaLibraryUrl' => route('media.index'),
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

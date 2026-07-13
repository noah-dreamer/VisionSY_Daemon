<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CampusIpRange;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'total_users' => User::count(),
                'active_users' => User::where('status', UserStatus::Active->value)->count(),
                'pending_activation' => User::where('status', UserStatus::PendingActivation->value)->count(),
                'disabled_users' => User::where('status', UserStatus::Disabled->value)->count(),
                'campus_ranges' => CampusIpRange::where('enabled', true)->count(),
            ],
            'recentLogs' => AuditLog::latest('id')->limit(8)->get(),
        ]);
    }
}

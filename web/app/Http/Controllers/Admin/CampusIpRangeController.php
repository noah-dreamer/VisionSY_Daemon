<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampusIpRange;
use App\Services\AuditLogger;
use App\Services\CampusIpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class CampusIpRangeController extends Controller
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.campus-ips.index', [
            'ranges' => CampusIpRange::orderBy('cidr')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cidr' => ['required', 'string', 'max:64', 'unique:campus_ip_ranges,cidr'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['cidr' => 'CIDR 网段', 'description' => '说明']);

        if (! CampusIpService::isValidCidr($validated['cidr'])) {
            return back()->withErrors(['cidr' => 'CIDR 格式不正确，例如 10.0.0.0/16 或 2001:db8::/32。'])->withInput();
        }

        $range = CampusIpRange::create($validated + ['enabled' => true]);

        $this->audit->log('admin.campus_ip_created', $request->user(), 'campus_ip_range', (string) $range->id, [
            'cidr' => $range->cidr,
        ]);

        return back()->with('status', "校园网段 {$range->cidr} 已添加。");
    }

    public function update(Request $request, CampusIpRange $range): RedirectResponse
    {
        $validated = $request->validate([
            'cidr' => [
                'required', 'string', 'max:64',
                Rule::unique('campus_ip_ranges', 'cidr')->ignore($range->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['cidr' => 'CIDR 网段', 'description' => '说明']);

        if (! CampusIpService::isValidCidr($validated['cidr'])) {
            return back()->withErrors(['cidr_'.$range->id => 'CIDR 格式不正确，请检查后重试。']);
        }

        $before = ['cidr' => $range->cidr, 'description' => $range->description];
        $range->forceFill($validated)->save();

        $this->audit->log(
            'admin.campus_ip_updated',
            $request->user(),
            'campus_ip_range',
            (string) $range->id,
            ['before' => $before, 'after' => ['cidr' => $range->cidr, 'description' => $range->description]],
        );

        return back()->with('status', "网段 {$range->cidr} 已更新。");
    }

    public function toggle(Request $request, CampusIpRange $range): RedirectResponse
    {
        $range->forceFill(['enabled' => ! $range->enabled])->save();

        $this->audit->log(
            $range->enabled ? 'admin.campus_ip_enabled' : 'admin.campus_ip_disabled',
            $request->user(),
            'campus_ip_range',
            (string) $range->id,
            ['cidr' => $range->cidr],
        );

        return back()->with('status', "网段 {$range->cidr} 已".($range->enabled ? '启用' : '禁用').'。');
    }

    public function destroy(Request $request, CampusIpRange $range): RedirectResponse
    {
        $cidr = $range->cidr;
        $id = $range->id;
        $range->delete();

        $this->audit->log('admin.campus_ip_deleted', $request->user(), 'campus_ip_range', (string) $id, [
            'cidr' => $cidr,
        ]);

        return back()->with('status', "网段 {$cidr} 已删除。");
    }
}

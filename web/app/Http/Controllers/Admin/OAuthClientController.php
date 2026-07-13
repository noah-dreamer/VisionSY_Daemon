<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OAuthClientController extends Controller
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.oauth-clients.index', [
            'clients' => OAuthClient::orderBy('id')->get(),
        ]);
    }

    /** 创建客户端：secret 随机生成，仅本次展示一次。 */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:oauth_clients,client_id'],
            'redirect_uris' => ['required', 'string'],
        ], [], ['name' => '名称', 'client_id' => 'Client ID', 'redirect_uris' => '回调地址']);

        $uris = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $validated['redirect_uris']))));

        foreach ($uris as $uri) {
            if (! str_starts_with($uri, 'https://') && ! str_starts_with($uri, 'http://localhost')) {
                return back()->withErrors(['redirect_uris' => "回调地址必须为 https://（本地调试允许 http://localhost）：{$uri}"])->withInput();
            }
        }

        $secret = Str::random(48);

        $client = OAuthClient::create([
            'name' => $validated['name'],
            'client_id' => $validated['client_id'],
            'client_secret_hash' => password_hash($secret, PASSWORD_BCRYPT),
            'redirect_uris' => $uris,
            'scopes' => ['profile'],
        ]);

        $this->audit->log('admin.oauth_client_created', $request->user(), 'oauth_client', $client->client_id);

        return back()->with('status', "客户端已创建。Client Secret 仅展示一次，请妥善保存到密钥管理服务：{$secret}");
    }

    public function destroy(Request $request, OAuthClient $client): RedirectResponse
    {
        $clientId = $client->client_id;
        $client->delete();

        $this->audit->log('admin.oauth_client_deleted', $request->user(), 'oauth_client', $clientId);

        return back()->with('status', "客户端 {$clientId} 已删除。");
    }
}

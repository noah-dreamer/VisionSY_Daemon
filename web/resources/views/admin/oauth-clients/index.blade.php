@extends('layouts.admin')

@section('title', 'OAuth 客户端 · 管理后台')
@section('page-title', 'OAuth2 客户端')

@section('content')
    <div class="card max-w-2xl p-7">
        <h2 class="text-sm font-semibold text-ink-900">创建客户端</h2>
        <p class="mt-1.5 prose-muted">
            Client Secret 由系统随机生成，仅创建成功后展示一次，请立即保存到密钥管理服务（如 <SECRET_MANAGER>）。
        </p>

        <form method="POST" action="{{ route('admin.oauth-clients.store') }}" class="mt-5 grid gap-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="名称" :value="old('name')" placeholder="例如：ResourceSpace 媒体库" required />
                <x-field name="client_id" label="Client ID" :value="old('client_id')" placeholder="resourcespace" required mono />
            </div>

            <div>
                <label for="redirect_uris" class="form-label">回调地址（每行一个，必须 https://）</label>
                <textarea id="redirect_uris" name="redirect_uris" rows="3" class="form-input font-mono"
                          placeholder="https://media.example.com/plugins/visionsy_sso/pages/callback.php" required>{{ old('redirect_uris') }}</textarea>
                @error('redirect_uris') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div><button type="submit" class="btn-primary">创建客户端</button></div>
        </form>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-ink-100">
            <thead class="bg-ink-50/70">
                <tr>
                    <th class="table-head">名称</th>
                    <th class="table-head">Client ID</th>
                    <th class="table-head">回调地址</th>
                    <th class="table-head text-right">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($clients as $client)
                    <tr class="transition hover:bg-ink-50/60">
                        <td class="table-cell font-medium text-ink-900">{{ $client->name }}</td>
                        <td class="table-cell font-mono">{{ $client->client_id }}</td>
                        <td class="table-cell">
                            <ul class="space-y-1">
                                @foreach ($client->redirect_uris as $uri)
                                    <li class="break-all font-mono text-xs text-ink-600">{{ $uri }}</li>
                                @endforeach
                            </ul>
                        </td>
                        <td class="table-cell text-right">
                            <form method="POST" action="{{ route('admin.oauth-clients.destroy', $client) }}"
                                  x-data @submit="if (! confirm('确认删除客户端 {{ $client->client_id }}？相关令牌将立即失效。')) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger btn-sm">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-ink-400">尚无客户端</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

# VisionSY 修复指令 v2（单一测试方案：仅 MariaDB，简化版）

> 本文件替代旧版 `VisionSY-fix-instructions.md`。唯一变化：**只保留 MariaDB 独立测试库
> `visionsy_test` 一条路径，删除所有 SQLite 可选分支**，因为项目生产环境本来就是
> MariaDB，两套方案除了增加心智负担没有额外收益。
>
> 执行者须知：共 9 项任务（T1–T9），按顺序全部完成，不要自行简化、合并或跳过；
> 不要"顺手"重构无关代码。路径均相对仓库根目录。

---

## 0. 背景（不要跳过）

**症状**：默认种子账号登录报"邮箱或密码不正确"；「VisionSY: 迁移」任务无效，
只有「⚠ 重建开发数据库」（`migrate:fresh --seed`）能恢复，但生产环境不能靠这个。

**根因**：`.vscode/tasks.json` 的「VisionSY: 测试」任务在 app 容器**内**跑
`php artisan test`。容器通过 `env_file: .env` 注入了真实的
`DB_CONNECTION=mysql`/`DB_DATABASE=visionsy`（真实进程环境变量），`phpunit.xml`
里的 `<env force="false">` 因此被静默忽略，测试连到了开发库。而
`tests/Feature` 下 13 个测试类全部 `use RefreshDatabase`，非内存库时会在整轮
测试开始执行一次 `migrate:fresh`（drop 所有表且不 seed）。结果：开发库被清空，
`users` 表还在但没数据，`Auth::attempt` 找不到用户 → 报"邮箱或密码不正确"。

**与 `web/public/storage` 无关**：那只是 `storage:link` 生成的悬空软链接，只影响
上传文件访问，与登录鉴权无关，无需处理。

**修复策略（三层）**：
- **入口层**：唯一测试入口 `deploy/docker/test.mjs`，从容器外用
  `docker compose exec -e` 注入测试环境变量，指向独立库 `visionsy_test`。
- **保险丝层**：`web/tests/TestCase.php` 在 `RefreshDatabase` 动手前拦截，
  库名不以 `_test` 结尾就直接抛异常。
- **恢复层**：新增 `visionsy:ensure-admin` 命令，非破坏性地修复管理员账号，
  生产环境也能安全用。

---

## 任务总览

| # | 类型 | 文件 |
|---|---|---|
| T1 | 新建 | `deploy/docker/test.mjs` |
| T2 | 修改 | `.vscode/tasks.json` |
| T3 | 修改 | `web/tests/TestCase.php` |
| T4 | 修改 | `web/phpunit.xml`（只加注释） |
| T5 | 修改 | `web/app/Providers/AppServiceProvider.php` |
| T6 | 新建 | `web/app/Console/Commands/EnsureAdminCommand.php` |
| T7 | 修改 | `deploy/docker/deploy.mjs`、`deploy/docker/init.mjs` |
| T8 | 修改 | `deploy/docker/.env*` 换行符 + 新建 `.gitattributes` |
| T9 | 新建/修改 | `AGENTS.md` + 文档纠错 |
| T10 | 验收 | 按清单逐条验证 |

---

## T1. 新建 `deploy/docker/test.mjs`

```javascript
#!/usr/bin/env node
/**
 * VisionSY 测试入口（**唯一**允许的运行测试方式）。
 *
 * 为什么必须走这个脚本：
 *   app 容器通过 `env_file: .env` 注入了真实的 DB_/CACHE_/SESSION_/QUEUE_ 等变量，
 *   它们是**真实进程环境变量**。`web/phpunit.xml` 里的 <env> 默认 force="false"
 *   （变量已存在则不覆盖），因此在容器内直接 `php artisan test` 时，phpunit.xml
 *   声明的测试配置会被静默忽略，测试直接跑在开发库上。而 web/tests/Feature 下
 *   13 个测试类全部 use RefreshDatabase —— 它在整轮测试开始时执行一次
 *   `migrate:fresh`（drop 所有表并重建，且不 seed）。结果就是开发库被清空、
 *   种子账号消失，表现为"邮箱或密码不正确"。
 *
 *   本脚本用 `docker compose exec -e` 从容器外注入测试环境变量（优先级最高），
 *   并把数据库指向独立的一次性测试库 visionsy_test，永不触碰开发库 visionsy。
 *
 * 用法（在 deploy/docker 下执行，或 `node deploy/docker/test.mjs`）：
 *   node test.mjs                          # 全部测试
 *   node test.mjs --filter=AuthenticationTest
 */
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const ENV_FILE = join(here, '.env');
const COMPOSE = ['-f', 'compose.yaml', '-f', 'compose.dev.yaml', '--env-file', '.env'];

/** 一次性测试库名。必须以 `_test` 结尾：web/tests/TestCase.php 的保险丝据此放行。 */
const TEST_DB = 'visionsy_test';

if (!existsSync(ENV_FILE)) {
    console.error(`[test] 未找到 ${ENV_FILE}。请先执行任务「VisionSY: 初始化开发环境」。`);
    process.exit(1);
}

const passthrough = process.argv.slice(2).filter((a) => a !== '--');

/**
 * 测试专用环境变量。
 * 覆盖范围 = phpunit.xml 声明过、且 deploy/docker/.env 里也存在的所有键
 * （这些键在容器内会被 .env 的真实值"压住"，必须在这里显式重置）。
 * 新增此类配置项时，phpunit.xml 与本对象要同步维护。
 */
const testEnv = {
    APP_ENV: 'testing',
    APP_DEBUG: 'true',
    APP_MAINTENANCE_DRIVER: 'file',
    BCRYPT_ROUNDS: '4',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    TRUSTED_PROXY_IPS: '127.0.0.1',
    INTERNAL_API_SECRET: 'test-only-placeholder',
    TRANSFER_TOKEN_TTL_SECONDS: '600',
    TRANSFER_TOKEN_ONE_TIME: 'true',
    CAPTCHA_ENABLED: 'false',
    CAPTCHA_MIN_SOLVE_SECONDS: '0',
    EMAIL_CODE_RESEND_COOLDOWN: '60',
    MEDIA_DIRECT_SERVE: 'true',
    MEDIA_GUEST_BROWSE: 'true',
    MEDIA_TEACHER_CAN_UPLOAD: 'true',
    DB_CONNECTION: 'mysql',
    DB_DATABASE: TEST_DB,
};

function compose(args) {
    return spawnSync('docker', ['compose', ...COMPOSE, ...args], {
        cwd: here,
        stdio: 'inherit',
        shell: false,
        encoding: 'utf8',
    });
}

function composeOrExit(args, label) {
    const res = compose(args);
    if (res.status !== 0) {
        console.error(`\n[test] ${label} 失败（退出码 ${res.status}）。容器是否已启动？先执行「VisionSY: 一键部署 (开发)」。`);
        process.exit(res.status ?? 1);
    }
}

console.log(`[test] 目标库 = ${TEST_DB}（独立于开发库 visionsy）`);
// 幂等创建测试库并授权给应用用户（口令取自 db 容器内环境变量，不经过宿主机命令行）。
const sql =
    'CREATE DATABASE IF NOT EXISTS \\`' + TEST_DB + '\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; ' +
    'GRANT ALL PRIVILEGES ON \\`' + TEST_DB + '\\`.* TO \'$MARIADB_USER\'@\'%\'; ' +
    'FLUSH PRIVILEGES;';
composeOrExit(
    ['exec', '-T', 'db', 'sh', '-c', `mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "${sql}"`],
    `创建测试库 ${TEST_DB}`
);

const envArgs = Object.entries(testEnv).flatMap(([k, v]) => ['-e', `${k}=${v}`]);

console.log('[test] 运行 php artisan test ...\n');
const res = compose(['exec', '-T', ...envArgs, 'app', 'php', 'artisan', 'test', ...passthrough]);
process.exit(res.status ?? 1);
```

**注意事项（不要"优化"掉）**：
- `-e` 参数必须出现在服务名 `app` 之前（`docker compose exec` 的语法要求）。
- SQL 里的反引号写成 `\\`` 是必要的：字符串最终进入 `sh -c "..."` 的双引号内，
  不转义会被当命令替换。

---

## T2. 修改 `.vscode/tasks.json`

### T2.1 替换「VisionSY: 测试」任务（只有这一个测试任务，不要新增其它变体）

```json
        {
            "label": "VisionSY: 测试",
            "detail": "在隔离测试库 visionsy_test 上运行全部测试。禁止裸跑 php artisan test（会清空开发库）",
            "type": "process",
            "command": "node",
            "args": ["test.mjs"],
            "options": { "cwd": "${workspaceFolder}/deploy/docker" },
            "presentation": { "reveal": "always", "panel": "dedicated", "group": "visionsy" },
            "problemMatcher": []
        },
```

### T2.2 新增「创建/修复管理员账号」与「用户数量自检」任务

```json
        {
            "label": "VisionSY: 创建/修复管理员账号",
            "detail": "幂等修复管理员登录（visionsy:ensure-admin）。非破坏性，生产亦可用",
            "type": "process",
            "command": "docker",
            "args": [
                "compose", "-f", "compose.yaml", "-f", "compose.dev.yaml", "--env-file", ".env",
                "exec", "-T", "app",
                "php", "artisan", "visionsy:ensure-admin", "${input:adminEmail}"
            ],
            "options": { "cwd": "${workspaceFolder}/deploy/docker" },
            "presentation": { "reveal": "always", "panel": "dedicated", "group": "visionsy" },
            "problemMatcher": []
        },
        {
            "label": "VisionSY: 用户数量自检",
            "detail": "登录失效时先跑这个：输出 users 表行数。返回 0 说明库被清空了",
            "type": "process",
            "command": "docker",
            "args": [
                "compose", "-f", "compose.yaml", "-f", "compose.dev.yaml", "--env-file", ".env",
                "exec", "-T", "app",
                "php", "artisan", "tinker", "--execute=echo 'users=' . App\\Models\\User::count() . PHP_EOL;"
            ],
            "options": { "cwd": "${workspaceFolder}/deploy/docker" },
            "presentation": { "reveal": "always", "panel": "dedicated", "group": "visionsy" },
            "problemMatcher": []
        },
```

### T2.3 新增 `inputs`（与 `"tasks"` 同级；若已存在则合并）

```json
    "inputs": [
        {
            "id": "adminEmail",
            "type": "promptString",
            "description": "管理员邮箱",
            "default": "admin@example.com"
        }
    ]
```

### T2.4 文件顶部注释追加

```
    // 运行测试：只用「VisionSY: 测试」（走 deploy/docker/test.mjs，隔离测试库）。
    // 绝不要执行 `docker compose exec app php artisan test`——它会清空开发数据库。
```

完成后用 JSONC 解析器验证文件合法。

---

## T3. 修改 `web/tests/TestCase.php`（保险丝，最关键的一项）

完整替换为：

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 保险丝：拒绝在非一次性数据库上运行测试。
     *
     * 背景：tests/Feature 下的测试类全部 use RefreshDatabase，它在整轮测试开始时
     * 执行一次 `migrate:fresh`（drop 所有表并重建，且不 seed）。若测试连到开发库或
     * 生产库，数据会被清空，表现为"邮箱或密码不正确"（users 表存在但为空）。
     *
     * 时序保证：Illuminate\Foundation\Testing\TestCase::setUp() 先调用
     * refreshApplication()，再调用 setUpTraits()（后者才触发 RefreshDatabase 的
     * migrate:fresh）。因此在这里拦截，可以保证一行数据都不会丢。
     *
     * ⚠️ 给未来的人类与 AI Agent：
     *    不要为了"让测试跑起来"而删除、注释或放宽本方法。
     *    测试失败时正确的做法是：node deploy/docker/test.mjs
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "拒绝在非一次性数据库上运行测试：connection={$connection}, database={$database}。".PHP_EOL
                .'测试使用 RefreshDatabase，会执行 migrate:fresh 清空整个数据库。'.PHP_EOL
                .'请改用：node deploy/docker/test.mjs      （隔离库 visionsy_test）'
            );
        }
    }
}
```

---

## T4. 修改 `web/phpunit.xml`（只加注释，不改值，**不要加 `force="true"`**）

在 `<php>` 标签内部第一行插入：

```xml
        <!--
            ⚠️ 注意：以下 <env> 默认 force="false"，即"环境变量已存在时不覆盖"。
            在 Docker 容器内运行测试时，app 容器已由 env_file: .env 注入了真实的
            DB_CONNECTION / DB_DATABASE / CACHE_STORE / INTERNAL_API_SECRET / CAPTCHA_* 等，
            因此这里的值会被静默忽略 —— 曾导致测试在开发库上执行 migrate:fresh，把库清空。

            容器内测试必须走 `node deploy/docker/test.mjs`（用 docker compose exec -e
            从外部注入，优先级最高）。此处保留这些值仅用于宿主机原生 php artisan test。

            不要在这里加 force="true"：那会反过来压掉 test.mjs 指定的 visionsy_test 库名。
            web/tests/TestCase.php 中有保险丝兜底。
        -->
```

---

## T5. 修改 `web/app/Providers/AppServiceProvider.php`

在 `use Illuminate\Support\Facades\RateLimiter;` 之前插入：

```php
use Illuminate\Support\Facades\DB;
```

`boot()` 方法开头插入：

```php
        // 生产环境禁用破坏性数据库命令（migrate:fresh / migrate:refresh / migrate:reset / db:wipe）。
        // 生产恢复管理员账号请用 `php artisan visionsy:ensure-admin`，切勿重建数据库。
        DB::prohibitDestructiveCommands($this->app->isProduction());
```

---

## T6. 新建 `web/app/Console/Commands/EnsureAdminCommand.php`

Laravel 会自动发现该目录下的命令，无需在 `bootstrap/app.php` 注册。

```php
<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 幂等创建 / 修复系统管理员账号。
 *
 * 目的：为"管理员登不进去"提供一条**非破坏性**恢复路径，生产环境可安全执行。
 * 只写 users 表的一行（外加一条审计日志），不 drop、不 truncate、不调用任何 Seeder
 * ——DatabaseSeeder 会连带执行 MediaDemoSeeder 生成演示素材，生产环境绝不能跑。
 *
 * 用法：
 *   php artisan visionsy:ensure-admin admin@example.com
 *   php artisan visionsy:ensure-admin admin@example.com --password=xxxxxx
 */
class EnsureAdminCommand extends Command
{
    protected $signature = 'visionsy:ensure-admin
        {email : 管理员邮箱}
        {--password= : 明文密码；省略时随机生成并打印一次}
        {--display-name= : 显示名（数据库唯一），冲突时自动加后缀}
        {--real-name= : 实名（system_admin 角色要求实名登记）}';

    protected $description = '幂等创建或修复系统管理员账号（非破坏性，可用于生产恢复）';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("邮箱格式无效：{$email}");

            return self::FAILURE;
        }

        $plainPassword = (string) ($this->option('password') ?: Str::password(20, symbols: false));
        $isGenerated = ! $this->option('password');

        $existing = User::where('email', $email)->first();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'password_hash' => $plainPassword, // User 模型将其声明为 'hashed' cast，赋明文即自动加密。
                'role' => UserRole::SystemAdmin,
                'status' => UserStatus::Active,
                'display_name' => $this->resolveDisplayName($existing),
                'real_name' => $this->option('real-name') ?: ($existing?->real_name ?: '系统管理员'),
                'email_verified_at' => $existing?->email_verified_at ?? now(),
                'activated_at' => $existing?->activated_at ?? now(),
            ]
        );

        AuditLog::create([
            'actor_id' => null,
            'actor_email' => 'console',
            'action' => $existing ? 'admin.ensure_admin_repaired' : 'admin.ensure_admin_created',
            'target_type' => 'user',
            'target_id' => (string) $user->id,
            'ip' => '127.0.0.1',
            'context' => ['email' => $email, 'password_rotated' => true],
        ]);

        $this->info(($existing ? '已修复' : '已创建')."系统管理员：{$user->email}（id={$user->id}）");
        $this->line("  角色  : {$user->role->value}");
        $this->line("  状态  : {$user->status->value}");
        $this->line("  显示名: {$user->display_name}");

        if ($isGenerated) {
            $this->newLine();
            $this->warn('随机生成的密码（仅显示这一次，请登录后立即修改）：');
            $this->line("  {$plainPassword}");
        }

        return self::SUCCESS;
    }

    /** users.display_name 有唯一索引：冲突时自动加数字后缀。 */
    private function resolveDisplayName(?User $existing): string
    {
        $wanted = (string) ($this->option('display-name') ?: $existing?->display_name ?: '系统管理员');

        $candidate = $wanted;
        $suffix = 2;

        while (
            User::where('display_name', $candidate)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists()
        ) {
            $candidate = $wanted.'_'.$suffix++;
        }

        return $candidate;
    }
}
```

---

## T7. 修改 `deploy/docker/deploy.mjs` 与 `deploy/docker/init.mjs`

**为什么**：`bootstrap_cache` 是命名卷，不会随镜像重建更新；一旦其中残留过
`config:cache`，Laravel 会跳过加载 `.env`，出现"改了配置却不生效"的现象。

### T7.1 `deploy/docker/deploy.mjs`

找到：

```javascript
console.log('\n[deploy] 3/3 执行迁移（migrate --force，非破坏性）...');
run(['exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force'], { label: ' [migrate]' });

console.log('\n[deploy] 建立 storage 软链接（storage:link，若已存在会跳过）...');
run(['exec', '-T', 'app', 'php', 'artisan', 'storage:link'], { label: ' [storage:link]' });
```

替换为：

```javascript
console.log('\n[deploy] 3/5 执行迁移（migrate --force，非破坏性）...');
run(['exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force'], { label: ' [migrate]' });

// 4) 清理启动缓存。bootstrap_cache 是命名卷：镜像重建不会更新它；一旦其中残留过
//    config 缓存，Laravel 会跳过加载 .env，出现"改了配置却不生效"的诡异现象。
console.log('\n[deploy] 4/5 清理启动缓存（optimize:clear）...');
run(['exec', '-T', 'app', 'php', 'artisan', 'optimize:clear'], { label: ' [optimize:clear]' });

console.log('\n[deploy] 5/5 建立 storage 软链接（storage:link，若已存在会跳过）...');
run(['exec', '-T', 'app', 'php', 'artisan', 'storage:link'], { label: ' [storage:link]' });

if (mode === 'prod') {
    console.log('\n[deploy] 生产缓存预热（optimize）...');
    run(['exec', '-T', 'app', 'php', 'artisan', 'optimize'], { label: ' [optimize]' });
}
```

删掉文件末尾生产提示里已自动执行的这一行：

```javascript
    console.log('  - 缓存预热 (config/route/view:cache)');
```

### T7.2 `deploy/docker/init.mjs`

把末尾提示

```javascript
console.log('  如需演示数据（仅可丢弃的开发库）：docker compose -f compose.yaml -f compose.dev.yaml exec -T app php artisan db:seed');
```

替换为

```javascript
console.log('  如需演示数据（仅可丢弃的开发库）：执行任务「VisionSY: ⚠ 重建开发数据库」。');
console.log('  只是管理员登不进去（不想清空数据）：执行任务「VisionSY: 创建/修复管理员账号」。');
console.log('  运行测试：执行任务「VisionSY: 测试」（node deploy/docker/test.mjs，隔离测试库）。');
```

改完用 `node --check deploy/docker/deploy.mjs` 和 `node --check deploy/docker/init.mjs` 验证语法。

---

## T8. 换行符：`deploy/docker/.env*` 转 LF + 新建 `.gitattributes`

**为什么**：这三个文件目前是 CRLF。若换用某些不剥离 `\r` 的工具读取，
`SEED_DEFAULT_PASSWORD` 会带上尾随 `\r`，种出来的密码哈希永远对不上——
表现和当前 bug 一模一样，但排查困难得多。

### T8.1 转换（在 `deploy/docker` 目录下执行）

```bash
node -e "const fs=require('fs');for(const f of ['.env','.env.example','.env.dev.example']){if(!fs.existsSync(f))continue;const s=fs.readFileSync(f,'utf8');fs.writeFileSync(f,s.replace(/\r\n/g,'\n'));console.log('converted to LF:',f)}"
```

只转换这三个文件，不要批量转换整个仓库。

### T8.2 新建仓库根 `.gitattributes`

```
# ============================================================
# VisionSY 换行符策略（仅约束对换行敏感的文件，避免全仓库 diff 噪音）
# 原因：Docker/Compose 与部分工具读取 env 文件时不会剥离 CRLF 的 \r，
#       会让 SEED_DEFAULT_PASSWORD 等值带上尾随 \r，产生极难排查的鉴权 bug。
# ============================================================

.env.example      text eol=lf
.env.*.example    text eol=lf
*.sh              text eol=lf
*.mjs             text eol=lf
```

---

## T9. 新建 `AGENTS.md` + 修正文档

### T9.1 新建仓库根 `AGENTS.md`

```markdown
# AGENTS.md —— 给 AI Agent 与协作者的硬性规则

本文件对所有在本仓库工作的自动化 Agent 生效。与其它文档冲突时，以本文件为准。

## 1. 运行测试

- ✅ 唯一允许的方式：`node deploy/docker/test.mjs`（可加 `--filter=Xxx`）。
- ❌ 严禁：`docker compose ... exec app php artisan test`、容器内裸跑 `php artisan test`、
  `vendor/bin/phpunit`。

原因：app 容器由 `env_file: .env` 注入了真实的 `DB_CONNECTION=mysql` / `DB_DATABASE=visionsy`，
会压掉 `phpunit.xml` 里 `force="false"` 的 `<env>` 值。`tests/Feature` 全部
`use RefreshDatabase`，会在整轮测试开始时执行 `migrate:fresh`（drop 所有表、不 seed）。
裸跑测试 = 清空开发数据库，症状是登录报"邮箱或密码不正确"。

`web/tests/TestCase.php` 里有保险丝会拦截这种情况。**不要删除、注释或放宽它**；
测试因此报错时，正确反应是改用 `node deploy/docker/test.mjs`。

## 2. 数据库

- 非破坏性迁移：`php artisan migrate --force`（或任务「VisionSY: 迁移」）。
- ❌ 严禁在任何非可丢弃开发库上执行 `migrate:fresh` / `migrate:refresh` /
  `migrate:reset` / `db:wipe` / `truncate`。生产环境已由
  `DB::prohibitDestructiveCommands()` 硬性拒绝。
- 管理员登不进去时，不要重建数据库。先自检再修复：
  1. `php artisan tinker --execute="echo App\Models\User::count();"` → 返回 0 说明库被清空了；
  2. `php artisan visionsy:ensure-admin admin@example.com`（幂等、非破坏性，只写一行）。
- ❌ 不要在生产运行 `db:seed`：`DatabaseSeeder` 会连带执行 `MediaDemoSeeder` 生成演示素材。

## 3. 秘密与环境文件

- 不读取、不打印、不提交 `deploy/docker/.env` 的真实值。
- `deploy/docker/.env*` 必须保持 LF 换行（见 `.gitattributes`）。

## 4. 交付要求

- 改完代码必须跑 `node deploy/docker/test.mjs` 并在结论中报告 pass/fail 数字；
  不要以"应该没问题"结案。
- 报告中必须包含：改了哪些文件、跑了哪些命令、以及是否触碰过数据库。
```

### T9.2 `deploy/docker/README.md`

把提到"测试用 SQLite `:memory:`，自包含，不依赖 MariaDB"的那段，替换为：

```
- **dev 镜像**经 `compose.dev.yaml` 的构建参数 `INSTALL_COMPOSER_DEV=1` 安装 Composer dev 依赖
  （phpunit/collision），因此开发容器内具备运行测试的能力。
- ⚠️ **必须用 `node deploy/docker/test.mjs` 运行，不要在容器内裸跑 `php artisan test`。**
  容器内 `env_file: .env` 注入的 `DB_CONNECTION=mysql` / `DB_DATABASE=visionsy` 是真实进程
  环境变量，会压掉 `phpunit.xml` 里 `force="false"` 的 `<env>` 值；而 `tests/Feature` 全部
  `use RefreshDatabase`，会在开发库上执行 `migrate:fresh`（drop 所有表且不 seed），
  直接后果是种子账号消失、登录报"邮箱或密码不正确"。
  `test.mjs` 用 `docker compose exec -e` 从外部注入测试变量，并指向独立的一次性库
  `visionsy_test`。
```

「验证清单」代码块中的

```
docker compose -p visionsy-test -f compose.yaml -f compose.dev.yaml exec app php artisan test
```

替换为

```
node test.mjs        # 测试必须走此入口（隔离测试库），不要裸跑 php artisan test
```

### T9.3 `docs/implementation/deployment-modernization-status.md`

把"MariaDB 全量测试：`docker compose exec app php artisan test`（当前仅 SQLite 验证）"
替换为：

```
  - **MariaDB 全量测试**：`node deploy/docker/test.mjs`（跑在独立测试库 `visionsy_test` 上）。
    ⚠️ 不要用 `docker compose exec app php artisan test`：容器内 `.env` 的真实
    `DB_CONNECTION`/`DB_DATABASE` 会压掉 `phpunit.xml`，`RefreshDatabase` 的 `migrate:fresh`
    会清空开发库（历史事故，已由 `web/tests/TestCase.php` 的保险丝拦截）。
```

### T9.4 `docs/architecture/deployment-and-dam-storage.md`

把「开发与生产差异」表中"自动化测试 | SQLite `:memory:`（phpunit.xml）| ..."一行替换为：

```
| 自动化测试 | 入口固定为 `node deploy/docker/test.mjs`；跑在独立库 `visionsy_test` 上（与生产同构） | 不在生产运行测试 |
```

### T9.5 `web/README.md`

- 把 `| 数据库 | 生产 MySQL；测试用 sqlite 内存库 |` 改为
  `` | 数据库 | 生产 / 开发 MariaDB；测试用独立库 `visionsy_test` | ``
- 把 `` `php artisan test` 需在你本机执行；测试只依赖 sqlite。`` 改为
  `` 测试必须通过 `node deploy/docker/test.mjs` 运行（隔离测试库）；不要裸跑 `php artisan test`。``

---

## T10. 验收（逐条执行并在报告中贴出结果）

```bash
# 均在 deploy/docker 目录下执行

# 1) 语法检查
node --check test.mjs
node --check deploy.mjs
node --check init.mjs
# .vscode/tasks.json 用 JSONC 解析器验证

# 2) 起环境（如未运行）
node deploy.mjs dev

# 3) 记录测试前的用户数
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan tinker --execute="echo 'before=' . App\Models\User::count() . PHP_EOL;"

# 4) 跑测试
node test.mjs

# 5) 关键验收：测试后用户数必须与第 3 步完全一致
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan tinker --execute="echo 'after=' . App\Models\User::count() . PHP_EOL;"

# 6) 验证保险丝真的会拦住裸跑（预期：立刻抛 RuntimeException，不产生任何表结构变化）
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan test --filter=DashboardTest
#    预期看到「拒绝在非一次性数据库上运行测试：connection=mysql, database=visionsy」

# 7) 再确认一次用户数没变
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan tinker --execute="echo 'after_guard=' . App\Models\User::count() . PHP_EOL;"

# 8) 验证恢复命令幂等（两次都应成功，第二次是"已修复"）
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan visionsy:ensure-admin admin@example.com --password=visionsy-dev
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env \
  exec -T app php artisan visionsy:ensure-admin admin@example.com --password=visionsy-dev

# 9) 用 admin@example.com / visionsy-dev 实际登录 http://localhost:8000 验证成功
```

**通过标准**：
- 第 3、5、7 步用户数完全相同（本次修复的核心证据）；
- 第 6 步被保险丝拦住并给出正确提示；
- 第 8、9 步成功登录；
- 第 4 步测试失败数不比修复前基线更多（若 `INTERNAL_API_SECRET`/`CAPTCHA_*` 现在
  终于生效使部分用例转为通过，请在报告中说明）。

---

## 明确禁止

1. 不要执行 `migrate:fresh` / `migrate:refresh` / `db:wipe` / `db:seed`，哪怕是为了"验证修复"。恢复账号用 `visionsy:ensure-admin`。
2. 不要删除、注释或放宽 `web/tests/TestCase.php` 的保险丝。
3. 不要在 `web/phpunit.xml` 里加 `force="true"`。
4. 不要读取或打印 `deploy/docker/.env` 中的真实秘密值。
5. 不要批量转换全仓库换行符，只处理 T8 指定的三个文件。
6. 不要顺手重构与本次修复无关的代码。
7. 不要引入 SQLite 测试路径——本项目测试只走 MariaDB `visionsy_test`。

#!/usr/bin/env node
/**
 * VisionSY 一键部署（跨平台，Node 实现，不依赖 bash/cmd 特有语法）。
 *
 * 流程：构建镜像 → 启动并等待健康（up -d --wait）→ 执行迁移（migrate --force）。
 * 迁移为【非破坏性】：只应用待执行迁移，不 seed、不 fresh，保留全部数据。
 *
 * 用法：
 *   node deploy.mjs dev    # 开发：compose.yaml + compose.dev.yaml（含 Composer dev 依赖、Vite）
 *   node deploy.mjs prod   # 生产：compose.yaml + compose.prod.yaml（生产镜像、不发布 DB 端口）
 *
 * 说明：
 *   - 这是「构建镜像 → 启动 → 迁移」三步的安全合并，等价于依次手动执行对应 VS Code 任务。
 *   - 它替代了过去「构建后登录失效 → 用 migrate:fresh --seed 恢复」的错误做法：
 *     真正原因是启动后未迁移、新代码跑在陈旧/半完成的库结构上；本脚本用 migrate --force 修正。
 *   - 生产环境执行前请先备份数据库（见 README「备份与恢复」）。
 */
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const ENV_FILE = join(here, '.env');

const mode = (process.argv[2] || 'dev').toLowerCase();
if (mode !== 'dev' && mode !== 'prod') {
    console.error(`[deploy] 未知模式 "${process.argv[2]}"。用法：node deploy.mjs dev | prod`);
    process.exit(2);
}

if (!existsSync(ENV_FILE)) {
    console.error(`[deploy] 未找到 ${ENV_FILE}。`);
    console.error(mode === 'prod'
        ? '  生产：cp .env.prod.example .env，填入真实秘密（来自密钥管理），勿入库。'
        : '  开发：执行任务「VisionSY: 初始化开发环境 (Docker)」生成 .env，或 cp .env.dev.example .env。');
    process.exit(1);
}

const override = mode === 'prod' ? 'compose.prod.yaml' : 'compose.dev.yaml';
const COMPOSE = ['-f', 'compose.yaml', '-f', override, '--env-file', '.env'];

// 跨平台同步等待（不依赖 sleep/timeout 命令）。
function sleepSync(ms) {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function run(args, { retries = 1, delayMs = 4000, label = '' } = {}) {
    let lastStatus = 1;
    for (let attempt = 1; attempt <= retries; attempt++) {
        const res = spawnSync('docker', ['compose', ...COMPOSE, ...args], {
            cwd: here,
            stdio: 'inherit',
            shell: false,
        });
        if (res.status === 0) {
            return;
        }
        lastStatus = res.status ?? 1;
        if (attempt < retries) {
            console.error(
                `\n[deploy]${label} 命令失败（退出码 ${lastStatus}）；可能为网络抖动，` +
                `${Math.round(delayMs / 1000)}s 后进行第 ${attempt + 1}/${retries} 次重试...`
            );
            sleepSync(delayMs);
        }
    }
    console.error(`\n[deploy]${label} 命令经 ${retries} 次尝试仍失败（退出码 ${lastStatus}）：docker compose ${COMPOSE.join(' ')} ${args.join(' ')}`);
    process.exit(lastStatus);
}

console.log(`[deploy] 模式 = ${mode}（${override}）`);

// 1) 构建镜像（网络敏感：BuildKit 需解析/拉取基础镜像，失败自动重试）
console.log('\n[deploy] 1/5 构建应用镜像...');
run(['build'], { retries: mode === 'prod' ? 3 : 5, delayMs: 4000, label: ' [build]' });

// 2) 启动并等待健康（--wait 会等待 healthcheck 通过；app 依赖 db 健康后才起）
console.log('\n[deploy] 2/5 启动服务并等待健康（up -d --wait）...');
run(['up', '-d', '--wait'], { retries: 4, delayMs: 4000, label: ' [up]' });

// 3) 迁移（非破坏性，不 seed）。这是发布步骤的核心，确保库结构与代码一致。
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

if (mode === 'dev') {
    console.log('\n[deploy] 完成。');
    console.log('  Web      : http://localhost:8000');
    console.log('  Vite HMR : http://localhost:5173');
    console.log('  首次或需要演示数据时（仅可丢弃开发库）：执行任务「⚠ 重建可丢弃开发数据库 (危险)」。');
} else {
    console.log('\n[deploy] 完成。建议随后执行生产任务：');

    console.log('  - 健康诊断 (visionsy:health-diagnostics) 与 curl /up');
}

#!/usr/bin/env node
/**
 * VisionSY 开发环境初始化（跨平台，Node 实现，不依赖 bash/cmd 特有语法）。
 *
 * 步骤（任一在重试耗尽后失败才退出）：
 *   1. 若 .env 不存在，从 .env.dev.example 复制（绝不覆盖已有 .env）。
 *   2. 构建应用镜像（含前端资源）。网络敏感，失败自动重试。
 *   3. 启动服务（app/worker/scheduler/db）。网络敏感（可能拉取镜像），失败自动重试。
 *   4. 若 APP_KEY 为空或占位符，生成并写回 .env（key:generate --show，不改容器内文件），
 *      然后重建容器使其生效。
 *   5. 执行数据库迁移（migrate，非 migrate:fresh；不 seed）。
 *
 * 说明：BuildKit 每次构建都会向 Docker Hub 解析基础镜像元数据；在到 registry 连接
 * 不稳定的环境下可能间歇性失败（EOF / TLS 握手失败等），故对 build/up 做带退避的重试。
 *
 * 用法：node init.mjs
 */
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const ENV_FILE = join(here, '.env');
const ENV_EXAMPLE = join(here, '.env.dev.example');

const COMPOSE_ARGS = ['-f', 'compose.yaml', '-f', 'compose.dev.yaml', '--env-file', '.env'];

// 跨平台同步等待（不依赖 sleep/timeout 命令）。
function sleepSync(ms) {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function run(cmd, args, { capture = false, retries = 1, delayMs = 4000 } = {}) {
    let lastStatus = 1;
    for (let attempt = 1; attempt <= retries; attempt++) {
        const res = spawnSync(cmd, args, {
            cwd: here,
            stdio: capture ? ['inherit', 'pipe', 'inherit'] : 'inherit',
            shell: false,
            encoding: 'utf8',
        });
        if (res.status === 0) {
            return capture ? (res.stdout ?? '') : '';
        }
        lastStatus = res.status ?? 1;
        if (attempt < retries) {
            console.error(
                `\n[init] 命令失败（退出码 ${lastStatus}）；可能为网络抖动，` +
                `${Math.round(delayMs / 1000)}s 后进行第 ${attempt + 1}/${retries} 次重试...`
            );
            sleepSync(delayMs);
        }
    }
    console.error(`\n[init] 命令经 ${retries} 次尝试仍失败（退出码 ${lastStatus}）：${cmd} ${args.join(' ')}`);
    process.exit(lastStatus);
}

function compose(args, opts = {}) {
    return run('docker', ['compose', ...COMPOSE_ARGS, ...args], opts);
}

function composeCapture(args) {
    return run('docker', ['compose', ...COMPOSE_ARGS, ...args], { capture: true });
}

// 1) 准备 .env（不覆盖）
if (!existsSync(ENV_FILE)) {
    if (!existsSync(ENV_EXAMPLE)) {
        console.error('[init] 缺少 .env.dev.example，无法初始化 .env。');
        process.exit(1);
    }
    copyFileSync(ENV_EXAMPLE, ENV_FILE);
    console.log('[init] 已从 .env.dev.example 创建 .env（未覆盖任何已有文件）。');
} else {
    console.log('[init] .env 已存在，保持不变。');
}

// 2) 构建镜像（网络敏感：BuildKit 需解析基础镜像元数据，失败自动重试）
console.log('\n[init] 构建应用镜像（含前端资源）...');
compose(['build'], { retries: 5, delayMs: 4000 });

// 3) 启动服务（可能拉取镜像，失败自动重试）
console.log('\n[init] 启动服务...');
compose(['up', '-d'], { retries: 4, delayMs: 4000 });

// 4) APP_KEY：为空或占位符则生成并写回 .env
const envText = readFileSync(ENV_FILE, 'utf8');
const keyMatch = envText.match(/^APP_KEY=(.*)$/m);
const currentKey = keyMatch ? keyMatch[1].trim() : '';
const needKey = currentKey === '' || currentKey === '<APP_KEY>';

if (needKey) {
    console.log('\n[init] 生成 APP_KEY...');
    const out = composeCapture(['exec', '-T', 'app', 'php', 'artisan', 'key:generate', '--show']);
    const generated = (out.match(/base64:[A-Za-z0-9+/=]+/) || [])[0];
    if (!generated) {
        console.error('[init] 未能从 key:generate --show 输出解析 APP_KEY：\n' + out);
        process.exit(1);
    }
    const updated = keyMatch
        ? envText.replace(/^APP_KEY=.*$/m, `APP_KEY=${generated}`)
        : envText + `\nAPP_KEY=${generated}\n`;
    writeFileSync(ENV_FILE, updated);
    console.log('[init] APP_KEY 已写入 .env，重建容器使其生效...');
    compose(['up', '-d'], { retries: 3, delayMs: 4000 });
} else {
    console.log('[init] APP_KEY 已配置，跳过生成。');
}

// 5) 迁移（非破坏性；不 seed）
console.log('\n[init] 执行数据库迁移（php artisan migrate）...');
compose(['exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force']);

console.log('\n[init] 建立 storage 软链接（storage:link，若已存在会跳过）...');
compose(['exec', '-T', 'app', 'php', 'artisan', 'storage:link']);

console.log('\n[init] 完成。');
console.log('  Web      : http://localhost:8000');
console.log('  Vite HMR : http://localhost:5173');
console.log('  如需演示数据（仅可丢弃的开发库）：docker compose -f compose.yaml -f compose.dev.yaml exec -T app php artisan db:seed');

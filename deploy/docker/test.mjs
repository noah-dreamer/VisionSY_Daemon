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

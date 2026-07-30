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

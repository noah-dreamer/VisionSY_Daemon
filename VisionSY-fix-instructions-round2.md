# VisionSY 修复指令 · 第二轮（修正版，整体交给代码 Agent 执行）

> 执行者须知：本文件共 2 项任务（T12、T13）。上一轮 T1–T10（`VisionSY-fix-instructions.md`）
> 与其后的 `VisionSY-fix-instructions-v2.md`（改为仅 MariaDB 单一测试路径、删除 SQLite 分支）
> 均已核实落实到位，本文件不再重复，也**不再引入任何 SQLite 相关内容**——项目已明确决定
> 测试只走 MariaDB 独立库 `visionsy_test`，`deploy/docker/test.mjs` 没有 `--sqlite` 参数，
> `tests/TestCase.php` 的保险丝也只认 `_test` 结尾的库名，不区分驱动。
> 所有路径相对仓库根目录。

---

## 0. 背景核对结论

`DashboardTest` 与 `MediaLibraryTest` 里各有一个失败用例。逐文件核对后，结论是**两处都不是
需要你二次确认产品意图的设计分歧，是明确的测试缺陷，可以直接修**：

- **`DashboardTest::test_active_user_sees_enter_media_library_button`**：老版本遗留。
  `config/visionsy.php` 的 `urls` 数组只有 `site / public_site / external_transfer /
  lan_transfer / auth`，没有 `media` 键；`DashboardController::show()` 传给视图的
  `mediaLibraryUrl` 就是 `route('media.index')`；`dashboard.blade.php` 只渲染这个内部路由。
  全仓库唯一出现 `https://media.example.com` 字符串的地方就是这一行断言本身——"媒体库要不要
  独立域名"这个决策早就落地了（答案是内置 `/media` 路由），只是断言没跟着更新。

- **`MediaLibraryTest::test_guest_can_browse_index_and_thumbs_but_not_preview`**：**不是权限
  漏洞，是测试 bug**。`media.preview` 路由本来就在 `auth` 中间件组内，`MediaResourcePolicy::preview()`
  对 `$user === null` 也直接拒绝——生产行为是对的。问题是该用例先 `uploadAs($member)`（内部调用
  了 `actingAs($member)`），Laravel 测试里这个绑定会一直作用于该测试方法后续所有请求，不会因为
  "接下来只是访问只读接口"而自动失效，所以"假装访客"的三行断言其实全程带着 `$member` 的登录态，
  而 `$member` 对自己上传的素材本就有预览权限，所以观测到 200 而不是重定向登录页。本项目在
  `tests/Feature/AdminPanelTest.php` 的 `test_non_admin_cannot_access_admin_panel` 里已经用
  `auth()->logout()` 修过一次一模一样的坑，这次只是补齐。

---

## 任务总览

| # | 类型 | 文件 |
|---|---|---|
| T12 | 修改 | `web/tests/Feature/DashboardTest.php` |
| T13 | 修改 | `web/tests/Feature/MediaLibraryTest.php` |
| T14 | 验收 | 按清单逐条验证 |

---

## T12：`web/tests/Feature/DashboardTest.php`（修正老版本"独立媒体域名"残留断言）

找到：

```php
    public function test_active_user_sees_enter_media_library_button(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('进入媒体库')
            ->assertSee('https://media.example.com');
    }
```

替换为：

```php
    public function test_active_user_sees_enter_media_library_button(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('进入媒体库')
            ->assertSee(route('media.index'));
    }
```

**说明**：媒体库自始至终就是内置的 `/media` 路由，`https://media.example.com` 是唯一一处、
且从未被任何配置或代码实际使用过的孤立字符串。改为断言真实的 `route('media.index')`，
测试才对得上代码的真实行为；以后真要拆成独立域名，这一行也会随 `route()` 解析出的新地址
自动更新，不用再改测试。

---

## T13：`web/tests/Feature/MediaLibraryTest.php`（修正"访客"断言实际仍是登录态的测试 bug）

找到：

```php
    public function test_guest_can_browse_index_and_thumbs_but_not_preview(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $this->get(route('media.index'))->assertOk()->assertSee('校运会开幕式');
        $this->get(route('media.thumb', $resource))->assertOk();
        $this->get(route('media.preview', $resource))->assertRedirect(route('login'));
    }
```

替换为：

```php
    public function test_guest_can_browse_index_and_thumbs_but_not_preview(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        // uploadAs() 内部调用了 actingAs($member)，Laravel 测试里该绑定会一直作用于本方法
        // 后续所有请求，不会因为"接下来只是访问只读接口"而自动失效。显式登出才能让下面这几行
        // 请求真正以访客身份发出（本项目已有先例：tests/Feature/AdminPanelTest.php 里
        // test_non_admin_cannot_access_admin_panel 用的是同一手法）。
        auth()->logout();

        $this->get(route('media.index'))->assertOk()->assertSee('校运会开幕式');
        $this->get(route('media.thumb', $resource))->assertOk();
        $this->get(route('media.preview', $resource))->assertRedirect(route('login'));
    }
```

**说明**：这不是权限漏洞。`routes/web.php` 里 `media.preview` 本来就在 `auth` 中间件组内，
`MediaResourcePolicy::preview()` 对 `$user === null` 也是直接拒绝——生产环境访客确实拿不到
预览图。之前测试失败是因为 `uploadAs($member)` 里的 `actingAs()` 让"假装访客"的三行请求其实
仍带着 `$member` 的登录态。加一行 `auth()->logout()` 让测试真正模拟访客，断言就会和线上行为
一致。

---

## T14. 验收

```bash
# 只跑受影响的两个测试类
node deploy/docker/test.mjs --filter=DashboardTest
node deploy/docker/test.mjs --filter=MediaLibraryTest

# 全量跑一遍，确认没有引入新的失败
node deploy/docker/test.mjs
```

**通过标准**：

- `DashboardTest::test_active_user_sees_enter_media_library_button` 与
  `MediaLibraryTest::test_guest_can_browse_index_and_thumbs_but_not_preview` 均通过；
- 全量测试通过数不低于修改前（不应有除这两个用例之外的其它用例被顺带改坏）。

请在结论中报告：改了哪些文件、各步命令的 pass/fail 数字。

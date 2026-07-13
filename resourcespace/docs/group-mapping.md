# ResourceSpace 用户组映射（固定，勿改名）

官网角色与 ResourceSpace 用户组一一对应。官网 `config/visionsy.php` 的 `role_group_map` 与本表必须保持一致；RS 侧需手工创建下列用户组（系统设置 → 用户组管理），组名带 `visionsy_` 前缀以避免与 RS 内建组冲突。

## 一、映射表

| 官网角色（role） | RS 用户组名 | RS 基础权限参照 | 关键能力 |
| --- | --- | --- | --- |
| `system_admin` | `visionsy_system_admin` | Administrators | 全部资源管理、系统配置、用户管理 |
| `propaganda_member` | `visionsy_propaganda_member` | General users + 上传 | 上传素材、编辑元数据、**下载无水印原图** |
| `wechat_editor_teacher` | `visionsy_wechat_editor_teacher` | General users | 浏览检索、**下载无水印原图**（不可上传） |
| `normal_user` | `visionsy_normal_user` | Restricted users | 浏览检索、仅可下载**带水印压缩图** |
| `guest` | `visionsy_guest` | Restricted users（只读） | 仅浏览公开资源缩略图，不可下载 |

## 二、同步规则

- 插件在每次 SSO 登录时调用 `userinfo`，以返回的 `groups[0]` 为准更新 RS 账号所属用户组：角色升降级在用户**下次登录**时生效。
- userinfo 的 `groups` 数组当前恒为单元素；若未来引入多组，以第一个为主组。
- RS 账号用户名使用官网 `sub`（用户 ID）派生：`visionsy_<sub>`，邮箱、显示名同步自 `email` / `name`，避免与 RS 本地账号冲突。
- 禁止在 RS 侧手工把 `visionsy_*` 账号移动到其他用户组——下次登录会被同步覆盖；权限调整一律在官网后台改角色。

## 三、组创建核对清单

在 RS 后台逐一创建以上 5 个组后，记录每个组的数字 ID，填入插件 `config.php` 的 `$visionsy_sso_group_map`（组名 → RS 组 ID 的映射），示例：

```php
$visionsy_sso_group_map = [
    'visionsy_system_admin'          => 1,   // 替换为实际组 ID
    'visionsy_propaganda_member'     => 10,
    'visionsy_wechat_editor_teacher' => 11,
    'visionsy_normal_user'           => 12,
    'visionsy_guest'                 => 13,
];
```

组 ID 在 RS「用户组管理」列表 URL 或数据库 `usergroup` 表可查。

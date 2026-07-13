<?php

namespace App\Enums;

enum UserRole: string
{
    case SystemAdmin = 'system_admin';
    case PropagandaMember = 'propaganda_member';
    case WechatEditorTeacher = 'wechat_editor_teacher';
    case NormalUser = 'normal_user';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => '系统管理员',
            self::PropagandaMember => '宣传部成员',
            self::WechatEditorTeacher => '官微编辑（老师）',
            self::NormalUser => '普通用户',
            self::Guest => '游客',
        };
    }

    /** ResourceSpace 用户组名（固定映射）。 */
    public function resourceSpaceGroup(): string
    {
        return config('visionsy.role_group_map')[$this->value];
    }

    /** 管理后台可创建的实名角色。 */
    public static function adminAssignable(): array
    {
        return [self::SystemAdmin, self::PropagandaMember, self::WechatEditorTeacher, self::NormalUser];
    }

    /** 必须实名登记的角色。 */
    public function requiresRealName(): bool
    {
        return in_array($this, [self::SystemAdmin, self::PropagandaMember, self::WechatEditorTeacher], true);
    }
}

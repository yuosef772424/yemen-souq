<?php

namespace App\Modules\Admin\Enums;

use App\Models\User;

/**
 * الدور، والموضع الوحيد الذي يقرأ العمود role.
 *
 * لماذا of(User) هنا ولا isAdmin() على موديل User: لأن App\Models\User
 * ملف لارافل الافتراضي خارج الوحدات، وكل سطر نضيفه إليه يربط الوحدة به.
 * الوحدة يجب أن تُحذف بحذف مجلدها. والعمود موجود في الجدول فـ$user->role
 * يعمل بلا أي تعديل على الموديل — لا cast ولا fillable ولا دالة.
 *
 * وtryFrom لا from: قيمة غريبة في العمود (هجرة يدوية، استعادة نسخة
 * قديمة) يجب أن تعني «عضو عادي» لا استثناءً يعطّل الموقع كله. الفشل
 * في اتجاه أقل الصلاحيات.
 */
enum UserRole: string
{
    case Member = 'member';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public static function of(?User $user): self
    {
        if ($user === null) {
            return self::Member;
        }

        return self::tryFrom((string) $user->getAttribute('role')) ?? self::Member;
    }

    public function label(): string
    {
        return match ($this) {
            self::Member => 'عضو',
            self::Moderator => 'مشرف محتوى',
            self::Admin => 'مدير',
        };
    }

    /** من يدخل لوحة التحكم أصلاً */
    public function isStaff(): bool
    {
        return $this !== self::Member;
    }

    /**
     * إدارة الحسابات والأدوار للمدير وحده.
     *
     * الفصل مقصود: مشرف المحتوى يحكم على إعلان، والحكم على حساب — حظره
     * أو ترقيته — قرار أعلى. ودمج الاثنين يعني أن أي مشرف يستطيع أن
     * يمنح نفسه صلاحية المدير، فيصير الدورين واحداً فعلياً.
     */
    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }

    /** الأدوار التي يجوز إسنادها من الواجهة */
    public static function assignable(): array
    {
        return [self::Member, self::Moderator, self::Admin];
    }
}

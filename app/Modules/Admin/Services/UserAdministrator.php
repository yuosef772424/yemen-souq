<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Enums\UserActionOutcome;
use App\Modules\Admin\Enums\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * إجراءات الحسابات — وموضع الحمايتين التي بلا لوحة تحكم تُقفَل بها.
 *
 * ═══ الحماية الأولى: لا فعل على النفس ═══
 *
 * مدير يستطيع حظر نفسه أو تجريد نفسه سيفعلها مرة واحدة بالخطأ، وحينها
 * لا سبيل للرجوع من داخل النظام إطلاقاً — يُصلَح بأمر artisan أو
 * باستعلام SQL يدوي على قاعدة الإنتاج.
 *
 * ═══ الحماية الثانية: آخر مدير محصَّن ═══
 *
 * لأن الأولى لا تكفي: مديران يجرّد كلٌّ منهما الآخر يُنتجان نظاماً بلا
 * مدير، وكل فحص فيه على النفس يمرّ بنجاح. الشرط الصحيح شرط على النظام
 * لا على الفاعل: «لا تنقص عن مدير واحد نشط».
 *
 * ولاحظ أن الحماية الثانية تشمل الإيقاف لا التجريد وحده — EnsureIsStaff
 * يمنع الموقوف من الدخول حتى لو بقي دوره admin، فإيقاف آخر مدير يترك
 * النظام بلا مدخل تماماً كتجريده.
 */
final class UserAdministrator
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function setStatus(User $target, User $admin, string $status, ?string $reason = null): UserActionOutcome
    {
        if (! in_array($status, ['active', 'suspended', 'banned'], true)) {
            return UserActionOutcome::NoChange;
        }

        if ($target->id === $admin->id) {
            return UserActionOutcome::Self;
        }

        if ($target->status === $status) {
            return UserActionOutcome::NoChange;
        }

        if ($status !== 'active' && $this->isLastActiveAdmin($target)) {
            return UserActionOutcome::LastAdmin;
        }

        DB::transaction(function () use ($target, $admin, $status, $reason): void {
            $before = $target->status;

            $target->forceFill(['status' => $status])->save();

            $this->audit->log($admin, match ($status) {
                'suspended' => AdminAction::UserSuspended,
                'banned' => AdminAction::UserBanned,
                default => AdminAction::UserActivated,
            }, $target, $reason, ['status_before' => $before, 'status_after' => $status]);
        });

        return UserActionOutcome::Done;
    }

    public function setRole(User $target, User $admin, UserRole $role, ?string $reason = null): UserActionOutcome
    {
        if ($target->id === $admin->id) {
            return UserActionOutcome::Self;
        }

        $current = UserRole::of($target);

        if ($current === $role) {
            return UserActionOutcome::NoChange;
        }

        if ($role !== UserRole::Admin && $this->isLastActiveAdmin($target)) {
            return UserActionOutcome::LastAdmin;
        }

        DB::transaction(function () use ($target, $admin, $role, $current, $reason): void {
            $target->forceFill(['role' => $role->value])->save();

            $this->audit->log($admin, AdminAction::UserRoleChanged, $target, $reason, [
                'role_before' => $current->value,
                'role_after' => $role->value,
            ]);
        });

        return UserActionOutcome::Done;
    }

    private function isLastActiveAdmin(User $target): bool
    {
        if (UserRole::of($target) !== UserRole::Admin) {
            return false;
        }

        return ! User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', 'active')
            ->whereKeyNot($target->id)
            ->exists();
    }
}

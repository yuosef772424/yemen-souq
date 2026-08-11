<?php

namespace App\Modules\Admin\Console;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Enums\UserRole;
use App\Modules\Admin\Services\AuditLogger;
use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Console\Command;

/**
 * منح دور من الطرفية.
 *
 *     php artisan admin:grant 777123456 --role=admin
 *     php artisan admin:grant someone@example.test --role=moderator
 *
 * البديل المرفوض: بذرة (seeder) تُنشئ مديراً بكلمة مرور معروفة. مرفوض
 * لأن البذرة تُنفَّذ على الخادم يوماً ما بحساب وكلمة مرور في مستودع
 * عام — وهذا أسوأ ثغرة يمكن أن تُشحن. هذا الأمر يرفع مستخدماً قائماً
 * سجّل بكلمة مروره، ولا يُنشئ حساباً ولا يعرف كلمة مرور.
 *
 * ويُكتب في سجل المساءلة بلا فاعل (admin_id فارغ) — والفراغ هنا معلومة
 * صحيحة: الإجراء جاء من الطرفية لا من حساب داخل النظام.
 */
class GrantRoleCommand extends Command
{
    protected $signature = 'admin:grant
                            {identifier : الهاتف أو البريد}
                            {--role=admin : member|moderator|admin}';

    protected $description = 'منح دور إداري لمستخدم قائم';

    public function handle(AuditLogger $audit): int
    {
        $role = UserRole::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->components->error('الدور غير معروف. المسموح: member، moderator، admin.');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('identifier');
        $phone = YemeniPhone::normalize($identifier);

        $user = User::query()
            ->when($phone !== null, fn ($q) => $q->where('phone', $phone))
            ->when($phone === null, fn ($q) => $q->where('email', $identifier))
            ->first();

        if ($user === null) {
            $this->components->error("لا حساب بهذا المعرّف: {$identifier}");
            $this->line('الأمر يرفع حساباً قائماً ولا يُنشئ حساباً — سجّل أولاً من /register.');

            return self::FAILURE;
        }

        $before = UserRole::of($user);

        if ($before === $role) {
            $this->components->info("{$user->name} على دور {$role->label()} أصلاً.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => $role->value])->save();

        $audit->log(null, AdminAction::UserRoleChanged, $user, 'من الطرفية', [
            'role_before' => $before->value,
            'role_after' => $role->value,
            'via' => 'artisan',
        ]);

        $this->components->info("{$user->name}: {$before->label()} ← {$role->label()}");

        return self::SUCCESS;
    }
}

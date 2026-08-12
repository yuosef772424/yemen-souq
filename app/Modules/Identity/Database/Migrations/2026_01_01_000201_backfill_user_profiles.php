<?php

use App\Models\User;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Database\Migrations\Migration;

/**
 * صفّ عرض لكل مستخدم لا يملك واحداً.
 *
 * `RegisterController` ينشئ الصفّ في معاملة، لكن البذور و`factory`
 * والاختبارات لم تكن تفعل — فكان في القاعدة أربعة عشر مستخدماً وثلاثة
 * ملفات شخصية. و`displayName()` تتراجع إلى `users.name` فلا يظهر عطل،
 * بينما كل ما يُبنى على الملف (النبذة، الموقع، الحساب التجاري) يعمل
 * على ربع المستخدمين.
 *
 * والمنع بعدها في `UserFactory::configure` — وهذه الهجرة تُصلح الماضي
 * وحده.
 *
 * `withTrashed`: حسابٌ أُغلق قد يُستعاد، ويجب أن يعود باسمه المعروض.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::withTrashed()
            ->whereDoesntHave('profile')
            ->select(['id', 'name'])
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    UserProfile::create([
                        'user_id' => $user->id,
                        'display_name' => $user->name,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // بلا تراجع: لا سبيل للتفريق بين ما أنشأته هذه الهجرة وما سبقها
    }
};

<?php

namespace Tests\Feature\Admin\Concerns;

use App\Models\User;
use App\Modules\Admin\Enums\UserRole;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Database\Seeders\CategorySeeder;
use App\Modules\Shared\Database\Seeders\CurrencySeeder;
use App\Modules\Shared\Database\Seeders\LocationSeeder;

/**
 * تجهيز مشترك لاختبارات لوحة التحكم.
 *
 * البذور الثلاث ضرورية لا زائدة: listings.category_id
 * وlocation_id مفاتيح أجنبية غير قابلة للفراغ، وListingObserver يحسب
 * price_in_base من أسعار الصرف — فبلا بذرة العملات يكون الحساب على
 * جدول فارغ، ويصير الاختبار يفحص سلوكاً لا يشبه الإنتاج.
 *
 * وforceFill للدور لا factory(['role' => ...]): المصانع تمرّ بـfill
 * فتُسقط أي عمود خارج $fillable في App\Models\User بلا خطأ — فيصير
 * الاختبار يظن أنه أنشأ مديراً وهو عضو، وينجح لسبب خاطئ.
 */
trait MakesAdminUsers
{
    protected function seedMarketplace(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(LocationSeeder::class);
    }

    protected function userWithRole(UserRole $role, string $status = 'active'): User
    {
        $user = User::factory()->create();

        $user->forceFill(['role' => $role->value, 'status' => $status])->save();

        return $user->refresh();
    }

    protected function admin(): User
    {
        return $this->userWithRole(UserRole::Admin);
    }

    protected function moderator(): User
    {
        return $this->userWithRole(UserRole::Moderator);
    }

    protected function member(): User
    {
        return $this->userWithRole(UserRole::Member);
    }

    protected function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes);
    }
}

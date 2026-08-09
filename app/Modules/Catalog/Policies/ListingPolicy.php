<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;

/**
 * قاعدة الملكية في موضع واحد.
 *
 * البديل المرفوض: ->where('user_id', auth()->id()) داخل كل استعلام في
 * كل متحكّم. يعمل، لكن القاعدة تصبح موزّعة على عشرة أسطر، ونسيانها في
 * سطر واحد يعني ثغرة IDOR كاملة: /account/listings/{slug}/edit لإعلان
 * شخص آخر. القاعدة هنا تُخترَق مرة واحدة أو لا تُخترَق.
 *
 * ولماذا لا auto-discovery: لارافل يبحث عن App\Policies\ListingPolicy
 * لموديل App\Models\Listing، والموديل هنا في مساحة اسم الوحدة —
 * فالربط يدوي في CatalogServiceProvider. هذا ثمن معماريّة الوحدات.
 */
class ListingPolicy
{
    public function view(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function update(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    /**
     * restore يعمل على صف محذوف منطقياً، وهذا مقصود: الملكية لا
     * تتغيّر بالحذف، فالمالك وحده من يستعيد.
     */
    public function restore(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function renew(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function viewStats(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    private function owns(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id;
    }
}
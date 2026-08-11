<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Database\Seeders\CategorySeeder;
use App\Modules\Shared\Database\Seeders\CurrencySeeder;
use App\Modules\Shared\Database\Seeders\LocationSeeder;
use Illuminate\Support\Str;

/**
 * تجهيز مشترك لاختبارات لا تخص وحدة التحكم.
 *
 * منفصل عن MakesAdminUsers بقصد: ذلك السمة يخص الأدوار والصلاحيات،
 * وهذا يخص السوق نفسه. دمجهما يجعل كل اختبار في المشروع يعتمد على
 * وحدة التحكم — فلا تُحذف الوحدة ولا تُنقل بلا كسر الاختبارات كلها.
 */
trait SeedsMarketplace
{
    protected function seedMarketplace(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(LocationSeeder::class);
    }

    protected function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes);
    }

    /** حدث سلوك خام — بلا مرور بـEventRecorder */
    protected function recordRawEvent(
        string $eventName,
        ?User $user,
        ?int $listingId,
        string $day,
    ): void {
        \Illuminate\Support\Facades\DB::table('user_events')->insert([
            'event_name' => $eventName,
            'occurred_at' => $day . ' 12:00:00',
            'user_id' => $user?->id,
            'anon_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'entity_type' => $listingId === null ? null : 'listing',
            'entity_id' => $listingId,
            'payload' => null,
            'context' => null,
        ]);
    }
}

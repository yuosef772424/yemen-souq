<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use Illuminate\Database\Seeder;

class ListingSeeder extends Seeder
{
    public function run(): void
    {
        if (User::count() < 10) {
            User::factory(10)->create();
        }

        Listing::factory(150)->create();

        $this->seedCurrencyEdgeCases();
    }

    /**
     * حالات مقصودة: نفس الرقم في عملات مختلفة.
     *
     * هذه ليست بيانات عشوائية — هي البرهان. من يفتح الموقع ويفلتر
     * بالسعر سيرى بعينه أن "100" لا تعني نفس الشيء في العملات الثلاث،
     * وأن الفلترة تتعامل معها بشكل صحيح.
     */
    private function seedCurrencyEdgeCases(): void
    {
        $category = Category::whereNotNull('parent_id')->first();
        $location = Location::where('level', 'district')->first();
        $user = User::first();

        $cases = [
            ['YER', 100, 'لعبة أطفال بسعر 100'],
            ['SAR', 100, 'ساعة يد بسعر 100'],
            ['USD', 100, 'سماعات بسعر 100'],
            ['YER', 50000, 'جوال مستعمل بسعر 50000'],
            ['SAR', 50000, 'سيارة بسعر 50000'],
            ['USD', 50000, 'شقة بسعر 50000'],
        ];

        foreach ($cases as [$currency, $amount, $title]) {
            Listing::factory()->create([
                'user_id' => $user?->id,
                'category_id' => $category?->id,
                'location_id' => $location?->id,
                'title' => $title,
                'price_amount' => $amount,
                'currency_code' => $currency,
                'price_type' => 'fixed',
                'status' => 'active',
                'published_at' => now(),
            ]);
        }
    }
}

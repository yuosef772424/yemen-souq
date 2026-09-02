<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تذكير «وثّق رقمك» في الترويسة.
 *
 * نمطٌ شائع في منصّات الإعلانات المبوّبة (دوبيزل مثلاً): ظاهرٌ باستمرار
 * في كل صفحة لا مرة عند النشر فقط، فالمستخدم يوثّق حين يناسبه.
 */
class HeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->subYear()->toDateString(),
            'source' => 'test', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function an_unverified_user_sees_the_reminder(): void
    {
        $user = User::factory()->create(['phone' => '+967771234567', 'phone_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('search'))
            ->assertOk()
            ->assertSee('وثّق رقمك')
            ->assertSee(route('phone.verify'), false);
    }

    #[Test]
    public function a_verified_user_does_not_see_the_reminder(): void
    {
        $user = User::factory()->create(['phone' => '+967771234567', 'phone_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('search'))
            ->assertOk()
            ->assertDontSee('وثّق رقمك');
    }

    #[Test]
    public function a_guest_does_not_see_the_reminder(): void
    {
        $this->get(route('search'))
            ->assertOk()
            ->assertDontSee('وثّق رقمك');
    }
}

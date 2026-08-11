<?php

namespace Tests\Feature\Merchant;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Merchant\Services\InteractionFeed;
use App\Modules\Merchant\Services\MerchantInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * لوحة التاجر.
 *
 * أهم اختبار هنا هو العزل: لوحة تعرض أرقام تاجر آخر ليست عيباً في العرض
 * بل تسريب بيانات تجارية. ولأن كل استعلام مقيَّد بـuser_id بلا Policy،
 * فالحرس الوحيد هو هذه الاختبارات.
 */
class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    private function stats(Listing $listing, array $values = []): void
    {
        DB::table('listing_daily_stats')->insert(array_merge([
            'listing_id' => $listing->id,
            'stat_date' => Carbon::today()->toDateString(),
            'views' => 0,
            'unique_viewers' => 0,
            'favorites' => 0,
            'contacts' => 0,
            'search_impressions' => 0,
            'search_clicks' => 0,
        ], $values));
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/merchant')->assertRedirect(route('login'));
        $this->get('/merchant/interactions')->assertRedirect(route('login'));
    }

    public function test_a_merchant_with_no_listings_sees_an_empty_state_not_an_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/merchant')
            ->assertOk()
            ->assertSee('لا إعلانات بعد');
    }

    public function test_numbers_are_scoped_to_the_signed_in_merchant(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $myListing = $this->listing(['user_id' => $mine->id, 'title' => 'إعلاني أنا']);
        $theirListing = $this->listing(['user_id' => $theirs->id, 'title' => 'إعلان غيري']);

        $this->stats($myListing, ['views' => 12]);
        $this->stats($theirListing, ['views' => 9000]);

        $response = $this->actingAs($mine)->get('/merchant')->assertOk();

        $response->assertSee('إعلاني أنا');
        $response->assertDontSee('إعلان غيري');
        $response->assertDontSee('9,000');
    }

    /** الإشارة التي تُبرّر الصفحة: ظهور كثير ونقر ضعيف */
    public function test_weak_click_rate_raises_a_signal(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id, 'status' => 'active']);

        $this->stats($listing, ['search_impressions' => 300, 'search_clicks' => 2, 'views' => 2]);

        $this->actingAs($merchant)
            ->get('/merchant')
            ->assertOk()
            ->assertSee('يظهر في البحث ولا يُنقر');
    }

    public function test_views_without_contacts_raises_a_signal(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id, 'status' => 'active']);

        $this->stats($listing, ['views' => 80, 'contacts' => 0]);

        $this->actingAs($merchant)
            ->get('/merchant')
            ->assertOk()
            ->assertSee('يُشاهَد ولا يُتواصَل');
    }

    /** تحت الحدّ لا إشارة: إنذار من ثلاث مشاهدات يعلّم التاجر تجاهل الإشارات */
    public function test_low_traffic_raises_nothing(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id, 'status' => 'active']);

        $this->stats($listing, ['views' => 3, 'search_impressions' => 5, 'search_clicks' => 0]);

        $this->actingAs($merchant)
            ->get('/merchant')
            ->assertOk()
            ->assertDontSee('يُشاهَد ولا يُتواصَل')
            ->assertDontSee('يظهر في البحث ولا يُنقر');
    }

    public function test_click_rate_stays_blank_below_the_minimum_impressions(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id]);

        $this->stats($listing, ['search_impressions' => 1, 'search_clicks' => 1]);

        $rows = app(MerchantInsights::class)->perListing($merchant);

        $this->assertNull($rows->firstWhere('id', $listing->id)->ctr);
    }

    public function test_unanswered_questions_are_urgent(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id]);

        DB::table('listing_questions')->insert([
            'listing_id' => $listing->id,
            'asker_id' => User::factory()->create()->id,
            'body' => 'هل السيارة مصدومة؟',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($merchant)
            ->get('/merchant')
            ->assertOk()
            ->assertSee('سؤال بلا جواب');
    }

    /** التفاعل: الأحدث أولاً ومن إعلاناتي وحدها */
    public function test_the_feed_shows_only_my_interactions(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $myListing = $this->listing(['user_id' => $mine->id]);
        $theirListing = $this->listing(['user_id' => $theirs->id]);

        DB::table('listing_questions')->insert([
            'listing_id' => $myListing->id,
            'asker_id' => $theirs->id,
            'body' => 'سؤال على إعلاني',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('listing_questions')->insert([
            'listing_id' => $theirListing->id,
            'asker_id' => $mine->id,
            'body' => 'سؤال على إعلان غيري',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($mine)
            ->get('/merchant/interactions')
            ->assertOk()
            ->assertSee('سؤال على إعلاني')
            ->assertDontSee('سؤال على إعلان غيري');
    }

    /** من حفظ الإعلان لا يُكشَف اسمه — قرار خصوصية لا تفصيل عرض */
    public function test_the_feed_never_names_who_favorited(): void
    {
        $merchant = User::factory()->create();
        $watcher = User::factory()->create(['name' => 'مشترٍ صامت']);
        $listing = $this->listing(['user_id' => $merchant->id]);

        DB::table('favorites')->insert([
            'user_id' => $watcher->id,
            'listing_id' => $listing->id,
            'created_at' => now(),
        ]);

        $this->actingAs($merchant)
            ->get('/merchant/interactions')
            ->assertOk()
            ->assertSee('أُضيف إلى المفضلة')
            ->assertDontSee('مشترٍ صامت');
    }

    public function test_the_feed_is_capped(): void
    {
        $merchant = User::factory()->create();
        $listing = $this->listing(['user_id' => $merchant->id]);
        $asker = User::factory()->create();

        foreach (range(1, InteractionFeed::TOTAL + 5) as $index) {
            DB::table('favorites')->insert([
                'user_id' => User::factory()->create()->id,
                'listing_id' => $listing->id,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $this->assertLessThanOrEqual(
            InteractionFeed::TOTAL,
            app(InteractionFeed::class)->forMerchant($merchant)->count()
        );
    }
}

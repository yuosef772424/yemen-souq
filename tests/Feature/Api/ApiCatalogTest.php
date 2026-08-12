<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * التصفّح والبحث وصفحة الإعلان، ثم النشر والتعديل من لوحة المعلن.
 */
class ApiCatalogTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    // ─── التصفّح العام ───

    #[Test]
    public function browsing_needs_no_token(): void
    {
        $this->listing(['title' => 'تويوتا كورولا موديل 2018 وكالة']);

        $this->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'title', 'price' => ['amount', 'currency', 'formatted']]], 'meta', 'links']);
    }

    /**
     * الفلترة السعرية تجري على العمود الموحّد لا على الرقم المكتوب.
     *
     * إعلان بمئة دولار وإعلان بمئة ريال ليسا في نفس النطاق — وهذا هو
     * السبب الأول لوجود `price_in_base`.
     */
    #[Test]
    public function the_price_filter_is_currency_aware(): void
    {
        $this->listing(['title' => 'آيفون 14 برو ماكس مستعمل', 'currency_code' => 'USD', 'price_amount' => 100]);
        $this->listing(['title' => 'شاحن آيفون أصلي جديد', 'currency_code' => 'YER', 'price_amount' => 100]);

        // مئة ريال فقط تقع تحت السقف — الدولار يساوي آلاف الريالات
        $response = $this->getJson('/api/v1/listings?max=1000&currency=YER')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('YER', $response->json('data.0.price.currency'));
    }

    /**
     * `match_mode` يُعاد للعميل: النتائج المتراجعة ليست ما طُلب تماماً،
     * وإخفاء ذلك يجعل التطبيق يعرضها كأنها المطابقة.
     */
    #[Test]
    public function the_response_declares_its_match_mode(): void
    {
        $this->listing(['title' => 'تويوتا كورولا موديل 2018 وكالة']);

        // القيم الأربع من SearchQueryBuilder::search — browse بلا استعلام،
        // exact للمطابقة التامة، near للمتراجعة، none لصفر نتائج
        $this->getJson('/api/v1/listings?q=كورولا')
            ->assertOk()
            ->assertJsonPath('meta.match_mode', 'exact');

        $this->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonPath('meta.match_mode', 'browse');

        $this->getJson('/api/v1/listings?q=غواصةنووية')
            ->assertOk()
            ->assertJsonPath('meta.match_mode', 'none');
    }

    #[Test]
    public function per_page_is_capped(): void
    {
        Listing::factory()->count(3)->create();

        $this->getJson('/api/v1/listings?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', (int) config('api.pagination.max'));
    }

    /** رابط عبثي يوسّع البحث ولا يُسقط الخادم — نفس درس SearchCriteria */
    #[Test]
    public function an_array_query_parameter_does_not_break_the_endpoint(): void
    {
        $this->getJson('/api/v1/listings?q[]=x&sort[]=y')->assertOk();
    }

    #[Test]
    public function a_listing_page_hides_the_contact_number(): void
    {
        $listing = $this->listing(['contact_phone' => '+967771234567']);

        $response = $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        // الرقم لا يُرسَل مع الصفحة — مساره مستقل بعدّاد
        $this->assertStringNotContainsString('771234567', $response->getContent());
        $response->assertJsonPath('abilities.can_reveal_contact', true);
    }

    #[Test]
    public function revealing_the_contact_returns_it_and_counts_it(): void
    {
        $listing = $this->listing(['contact_phone' => '+967771234567', 'contacts_count' => 0]);

        $this->postJson("/api/v1/listings/{$listing->slug}/contact")
            ->assertOk()
            ->assertJsonPath('phone', '+967771234567');

        $this->assertSame(1, $listing->fresh()->contacts_count);
    }

    #[Test]
    public function a_hidden_number_is_not_revealed(): void
    {
        $listing = $this->listing(['contact_phone' => '+967771234567', 'hide_phone' => true]);

        $this->postJson("/api/v1/listings/{$listing->slug}/contact")->assertNotFound();
    }

    /** زيارة المالك لا تُحسب — وإلا صار الرقم الذي جاء يقرأه من صنعه */
    #[Test]
    public function the_owner_visit_is_not_counted(): void
    {
        $owner = User::factory()->create();
        $listing = $this->listing(['user_id' => $owner->id, 'views_count' => 0]);

        // الزائر أولاً: actingAs يثبّت الحارس لبقية الاختبار، فطلبٌ
        // «بلا حساب» بعده ليس بلا حساب
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->assertSame(1, $listing->fresh()->views_count);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->assertSame(1, $listing->fresh()->views_count);
    }

    #[Test]
    public function a_paused_listing_is_hidden_from_everyone_but_its_owner(): void
    {
        $owner = User::factory()->create();
        $listing = $this->listing(['user_id' => $owner->id, 'status' => 'paused']);

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
    }

    // ─── بنية السوق ───

    #[Test]
    public function bootstrap_returns_everything_the_app_needs_at_startup(): void
    {
        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure([
                'version', 'categories', 'locations', 'currencies',
                'base_currency', 'exchange_rates', 'attributes_by_category', 'limits',
            ]);
    }

    /**
     * تعريف الخاصية يحمل قواعد تحققها — فيتحقّق العميل بنفس القاعدة
     * بلا نسخها، وإضافة خاصية بالبذرة تظهر محقَّقةً بلا إصدار جديد.
     */
    #[Test]
    public function category_attributes_carry_their_validation_rules(): void
    {
        $category = Category::has('attributes')->first();

        $this->getJson("/api/v1/categories/{$category->id}/attributes")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'key', 'name', 'data_type', 'is_required', 'is_filterable']]]);
    }

    #[Test]
    public function an_unknown_category_is_a_404_not_an_empty_list(): void
    {
        $this->getJson('/api/v1/categories/999999/attributes')->assertNotFound();
    }

    // ─── النشر ───

    #[Test]
    public function publishing_requires_a_verified_phone(): void
    {
        $user = User::factory()->create(['phone_verified_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/listings', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'phone_not_verified');
    }

    #[Test]
    public function a_verified_seller_publishes_a_listing(): void
    {
        $user = $this->verifiedSeller();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/listings', $this->validPayload())
            ->assertCreated();

        $this->assertDatabaseHas('listings', [
            'user_id' => $user->id,
            'title' => 'تويوتا كورولا موديل 2018 وكالة',
            'status' => 'active',
        ]);

        // الأعمدة المحسوبة يملؤها ListingObserver لا المتحكّم
        $this->assertNotNull($response->json('data.price.in_base'));
        $this->assertNotNull(Listing::first()->search_blob);
    }

    /**
     * الحقول المحظورة لا تمرّ ولو أُرسلت.
     *
     * حقلٌ واحد منها في fillable يعني أن أي معلن يميّز إعلانه أو يمدّده
     * بطلب معدَّل — وهي أهم قاعدة في UpdateListingRequest.
     */
    #[Test]
    public function a_seller_cannot_promote_their_own_listing(): void
    {
        $user = $this->verifiedSeller();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/listings', [
            ...$this->validPayload(),
            'is_featured' => true,
            'status' => 'sold',
            'expires_at' => now()->addYears(5)->toDateTimeString(),
        ])->assertCreated();

        $listing = Listing::first();

        $this->assertFalse((bool) $listing->is_featured);
        $this->assertSame('active', $listing->status);
        $this->assertTrue($listing->expires_at->lessThan(now()->addDays(31)));
    }

    #[Test]
    public function validation_errors_come_back_as_json(): void
    {
        $user = $this->verifiedSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/listings', ['title' => 'قصير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'category_id', 'location_id', 'price_type', 'contact_phone']);
    }

    // ─── لوحة المعلن ───

    #[Test]
    public function a_seller_only_sees_their_own_listings(): void
    {
        $user = $this->verifiedSeller();
        $this->listing(['user_id' => $user->id]);
        $this->listing(['user_id' => User::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/listings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.live', 1);
    }

    /**
     * أخطر اختبار في اللوحة: الملكية.
     *
     * بلا Gate في كل فعل تصير كل نقطة نهاية ثغرة IDOR كاملة.
     */
    #[Test]
    public function a_stranger_cannot_touch_someone_elses_listing(): void
    {
        $owner = $this->verifiedSeller();
        $stranger = $this->verifiedSeller();
        $listing = $this->listing(['user_id' => $owner->id]);

        $as = fn () => $this->actingAs($stranger, 'sanctum');

        $as()->getJson("/api/v1/me/listings/{$listing->slug}")->assertForbidden();
        $as()->postJson("/api/v1/me/listings/{$listing->slug}", $this->validPayload())->assertForbidden();
        $as()->deleteJson("/api/v1/me/listings/{$listing->slug}")->assertForbidden();
        $as()->postJson("/api/v1/me/listings/{$listing->slug}/renew")->assertForbidden();
        $as()->postJson("/api/v1/me/listings/{$listing->slug}/sold")->assertForbidden();
        $as()->getJson("/api/v1/me/listings/{$listing->slug}/stats")->assertForbidden();
    }

    #[Test]
    public function deleting_is_soft_and_restorable(): void
    {
        $user = $this->verifiedSeller();
        $listing = $this->listing(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/me/listings/{$listing->slug}")
            ->assertOk();

        $this->assertSoftDeleted('listings', ['id' => $listing->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/me/listings/{$listing->slug}/restore")
            ->assertOk();

        $this->assertNull($listing->fresh()->deleted_at);
    }

    /**
     * التجديد خارج النافذة يُرفض برمز — لا برسالة يطابقها العميل نصّاً.
     */
    #[Test]
    public function renewing_too_early_is_rejected_with_a_code(): void
    {
        $user = $this->verifiedSeller();
        $listing = $this->listing(['user_id' => $user->id, 'expires_at' => now()->addDays(20)]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/me/listings/{$listing->slug}/renew")
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_early');
    }

    #[Test]
    public function renewing_inside_the_window_extends_without_moving_published_at(): void
    {
        $user = $this->verifiedSeller();
        $listing = $this->listing([
            'user_id' => $user->id,
            'expires_at' => now()->addDays(2),
            'published_at' => now()->subDays(40),
        ]);
        $publishedAt = $listing->published_at;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/me/listings/{$listing->slug}/renew")
            ->assertOk()
            ->assertJsonPath('code', 'renewed');

        $fresh = $listing->fresh();

        $this->assertTrue($fresh->expires_at->greaterThan(now()->addDays(29)));
        // published_at لا يُلمس: تحريكه يجعل التجديد ترقيةً مجانية
        $this->assertSame($publishedAt->toDateTimeString(), $fresh->published_at->toDateTimeString());
    }

    #[Test]
    public function marking_sold_hides_the_listing_and_is_reversible(): void
    {
        $user = $this->verifiedSeller();
        $listing = $this->listing(['user_id' => $user->id, 'title' => 'كيا ريو مستعملة بحالة ممتازة']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/me/listings/{$listing->slug}/sold")
            ->assertOk();

        $this->assertSame('sold', $listing->fresh()->status);

        // اختفى من نتائج البحث — scopePublished يشترط active، فتغيير
        // الحالة وحده يُخفيه بلا لمس أي استعلام
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/me/listings/{$listing->slug}/sold")
            ->assertOk();

        $this->assertSame('active', $listing->fresh()->status);
        $this->assertNull($listing->fresh()->sold_at);
    }

    // ─── مساعدات ───

    /**
     * الرقم صريح هنا: `hasVerifiedPhone` تشترط العمودين معاً، و
     * `UserFactory` لا يملأ `phone` — فحسابٌ بتاريخ توثيق بلا رقم
     * يبقى ممنوعاً من النشر.
     */
    private function verifiedSeller(): User
    {
        return User::factory()->create([
            'phone' => '+9677'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
        ]);
    }

    private function validPayload(): array
    {
        return [
            'title' => 'تويوتا كورولا موديل 2018 وكالة',
            'description' => 'الحالة ممتازة والاستخدام بسيط.',
            'category_id' => Category::whereNotNull('parent_id')->value('id'),
            'location_id' => Location::where('level', 'district')->value('id'),
            'price_type' => 'fixed',
            'price_amount' => 5000000,
            'currency_code' => 'YER',
            'condition_type' => 'used',
            'contact_phone' => '0771234567',
        ];
    }
}

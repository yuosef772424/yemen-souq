<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * إعلانٌ ثانٍ ببيانات الأول يُرفض.
 *
 * الحالة التي دفعت إلى هذا: صفّان متطابقان في كل شيء إلا `created_at` —
 * ضغطتان على زرّ النشر، أو نشرٌ متعمَّد لنفس السلعة ليقفز الإعلان إلى
 * رأس «الأحدث». وأثرهما على من يتصفّح واحد.
 */
class DuplicateListingTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->publisher = User::factory()->create(['phone' => '+967771234567']);
        $this->publisher->markPhoneAsVerified();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'تويوتا كورولا 2018 وكالة',
            'description' => 'الحالة ممتازة والفحص مسموح.',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'price_type' => 'fixed',
            'price_amount' => 45000,
            'currency_code' => 'YER',
            'contact_phone' => '+967771234567',
            ...$overrides,
        ];
    }

    #[Test]
    public function the_same_listing_published_twice_is_refused(): void
    {
        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasErrors('title');

        $this->assertSame(1, Listing::count());
    }

    /** والرسالة تدلّ على الإعلان القائم لا على «خطأ ما» */
    #[Test]
    public function the_refusal_names_the_listing_it_duplicates(): void
    {
        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload());

        $original = Listing::firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasErrorsIn('default', [
                'title' => "لديك إعلان منشور بنفس هذه البيانات (رقم {$original->id}). "
                    .'جدّده من «إعلاناتي» ليعود إلى المقدمة، أو غيّر شيئاً في هذا الإعلان.',
            ]);
    }

    /**
     * الاختلاف في حقل واحد يكفي.
     *
     * وهو ما يجعل الحارس مقبولاً: من يملك سيارتين متشابهتين يفرّق
     * بينهما بسعرٍ أو عنوان، ولا يُمنع من البيع.
     */
    #[Test]
    public function a_single_changed_field_makes_it_a_new_listing(): void
    {
        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload());

        foreach ([
            ['title' => 'تويوتا كورولا 2019 وكالة'],
            ['price_amount' => 46000],
            ['description' => 'وصف آخر تماماً لسيارة أخرى.'],
        ] as $change) {
            $this->actingAs($this->publisher)
                ->post(route('listing.store'), $this->payload($change))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, Listing::count());
    }

    /**
     * التطبيع هو ما يمنع الالتفاف: نسخةٌ اختلفت بهمزةٍ أو مسافة تُرفض.
     */
    #[Test]
    public function a_copy_that_differs_only_in_spacing_or_hamza_is_refused(): void
    {
        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload([
            'title' => 'شقة للايجار في صنعاء',
        ]));

        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload([
                'title' => 'شقّة  للإيجار في صنعاء',
            ]))
            ->assertSessionHasErrors('title');

        $this->assertSame(1, Listing::count());
    }

    /** معلنان مختلفان ينشران سلعتين متشابهتين — سوقٌ لا تكرار */
    #[Test]
    public function two_sellers_may_publish_the_same_thing(): void
    {
        $other = User::factory()->create(['phone' => '+967772222222']);
        $other->markPhoneAsVerified();

        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload());

        $this->actingAs($other)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Listing::count());
    }

    /**
     * ومن غادر إعلانه السوق يحقّ له إعادة نشره.
     *
     * الحذف والانتهاء والبيع كلها حالات لا يرى فيها أحد الإعلان القديم،
     * فلا ازدواج. وهذا هو سبب غياب القيد الفريد من القاعدة: القيد لا
     * يعرف هذا الفرق.
     */
    #[Test]
    public function a_listing_that_left_the_market_may_be_published_again(): void
    {
        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload());

        Listing::firstOrFail()->delete();

        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Listing::count());
        $this->assertSame(2, Listing::withTrashed()->count());
    }

    #[Test]
    public function an_expired_listing_does_not_block_a_new_one(): void
    {
        $this->listingFor($this->publisher, [
            ...$this->payload(),
            'status' => 'expired',
        ]);

        $this->actingAs($this->publisher)
            ->post(route('listing.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Listing::count());
    }

    /** والتعديل لا يجعل إعلاناً نسخةً من غيره */
    #[Test]
    public function editing_a_listing_into_a_copy_of_another_is_refused(): void
    {
        $first = $this->listingFor($this->publisher, $this->payload());
        $second = $this->listingFor($this->publisher, $this->payload([
            'title' => 'هوندا سيفيك 2016',
        ]));

        $this->actingAs($this->publisher)
            ->put(route('account.listings.update', $second), $this->payload())
            ->assertSessionHasErrors('title');

        $this->assertSame('هوندا سيفيك 2016', $second->fresh()->title);
        $this->assertNotSame($first->fingerprint, $second->fresh()->fingerprint);
    }

    /** وإعلانٌ يُحفظ بلا تغيير لا يصطدم بنفسه */
    #[Test]
    public function saving_a_listing_unchanged_is_not_a_duplicate(): void
    {
        $listing = $this->listingFor($this->publisher, $this->payload());

        $this->actingAs($this->publisher)
            ->put(route('account.listings.update', $listing), $this->payload())
            ->assertSessionHasNoErrors();
    }

    /** ونفس الحارس في الـAPI — الطلب واحد والمتحكّمان اثنان */
    #[Test]
    public function the_api_refuses_the_duplicate_too(): void
    {
        $this->actingAs($this->publisher)->post(route('listing.store'), $this->payload());

        $this->actingAs($this->publisher, 'sanctum')
            ->postJson(route('api.v1.me.listings.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertSame(1, Listing::count());
    }
}

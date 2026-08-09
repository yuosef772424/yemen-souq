<?php

/**
 * scaffold-search.php — المرحلة الأولى من الخطة: تثبيت الاختبارات + سدّ ثغرات البحث.
 *
 * يُشغَّل من جذر المشروع:  php scaffold-search.php
 *
 * (1) إصلاح الاختبارات الـ39 الساقطة: User::isActive() المفقودة،
 *     واختبار الصور الذي يفترض نشراً بلا تسجيل دخول، واختباران هشّان
 *     من السكربت السابق.
 *
 * (2) البحث: حراسة المدخلات، سقف طول العبارة، المكافئات (search_synonyms
 *     كانت جدولاً ميتاً)، والتراجع من المطابقة التامة إلى الأقرب.
 */

if (! is_dir(__DIR__ . '/app/Modules/Search')) {
    fwrite(STDERR, "خطأ: شغّل هذا السكربت من جذر المشروع.\n");
    exit(1);
}

$files = [];

// ═════════════════════════════════════════════════════════════════════════════
// (1) الاستقرار
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Models/User.php'] = <<<'PHP'
<?php

namespace App\Models;

use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * كل ما تستدعيه وحدة الهوية من هذا النموذج، في مكان واحد.
 *
 * أُضيفت isActive() هنا: كان LoginController يناديها وهي غير موجودة،
 * فيُلقى BadMethodCallException *بعد* نجاح Auth::attempt — أي أن
 * المستخدم يُسجَّل دخوله ثم يرى صفحة خطأ 500. ولذلك بدت أعراضها
 * متناقضة: assertAuthenticatedAs ينجح وassertRedirect يسقط في نفس
 * المسار. هذا هو نوع العطل الذي يضيّع ساعة قبل أن يُفهم.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * phone قابل للتعيين الجماعي، وphone_verified_at وstatus ليسا كذلك
     * بقصد: التوثيق والإيقاف قرارا نظامٍ لا حقلا نموذجٍ يملؤهما المستخدم.
     * يُكتبان بـforceFill من الموضع الذي يملك القرار.
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ---- العلاقات ----

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function trackingPreferences(): HasOne
    {
        return $this->hasOne(TrackingPreference::class);
    }

    // ---- الهوية ----

    /**
     * الاسم المعروض يأتي من الملف الشخصي، ويرجع إلى name عند غيابه.
     * القالب يناديها لكل مستخدم مسجَّل، فلا يجوز أن تفترض وجود صف
     * user_profiles — مستخدم من factory أو seeder لا يملك واحداً.
     */
    public function displayName(): string
    {
        return $this->profile?->display_name ?: $this->name;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): void
    {
        if ($this->phone_verified_at !== null) {
            return;
        }

        $this->forceFill(['phone_verified_at' => now()])->save();
    }

    /**
     * الحساب العامل.
     *
     * القيمة الافتراضية في المخطط 'active'، لكن الشرط مكتوب صراحةً على
     * 'active' لا على «ليس موقوفاً»: إضافة حالة رابعة لاحقاً
     * (مثل pending_review) يجب أن تُقصي صاحبها حتى يُقرَّر فيها، لا أن
     * تمرّ لأنها ليست في قائمة الممنوعات.
     */
    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }
}
PHP;

$files['tests/Feature/Catalog/ListingImageUploadTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * كُتب هذا الملف قبل وجود المصادقة، فكان ينشر إعلاناً بلا حساب عبر
 * DemoUser. بعد إضافة بوابة النشر صار كل POST يُعاد توجيهه إلى /login،
 * فسقطت الخمسة عشر اختباراً — لا لعيب في معالجة الصور بل لأن الاختبار
 * يفترض عالماً لم يبق قائماً.
 *
 * الإصلاح هو تسجيل الدخول في setUp، لا تخفيف الحماية في المسار:
 * الاختبار هو ما تخلّف عن الكود، ولوحة المعلن كلها تعتمد على أن
 * /add محميّ فعلاً.
 */
class ListingImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->toDateString(), 'source' => 'test', 'created_at' => now(),
        ]);

        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->user = User::create([
            'name' => 'معلن مختبَر',
            'phone' => '+967771234567',
            'email' => 'publisher@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'تويوتا كورولا 2018 وكالة',
            'description' => 'الحالة ممتازة والفحص مسموح.',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'price_type' => 'fixed',
            'price_amount' => 45000,
            'currency_code' => 'YER',
            'contact_phone' => '+967771234567',
        ], $overrides);
    }

    #[Test]
    public function it_publishes_a_listing_with_images(): void
    {
        $response = $this->post(route('listing.store'), $this->payload([
            'images' => [
                UploadedFile::fake()->image('a.jpg', 1200, 900),
                UploadedFile::fake()->image('b.png', 800, 600),
            ],
        ]));

        $listing = Listing::firstOrFail();

        $response->assertRedirect(route('listing.show', $listing));
        $this->assertCount(2, $listing->images);
    }

    /** الإعلان يُنسب إلى الحساب المسجَّل لا إلى مستخدم تجريبي */
    #[Test]
    public function the_listing_belongs_to_the_signed_in_account(): void
    {
        $this->post(route('listing.store'), $this->payload());

        $this->assertSame($this->user->id, Listing::firstOrFail()->user_id);
    }

    #[Test]
    public function uploads_are_converted_to_webp(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('photo.jpg', 1000, 800)],
        ]));

        $image = Listing::firstOrFail()->images()->firstOrFail();

        $this->assertStringEndsWith('.webp', $image->path);
        Storage::disk('public')->assertExists($image->path);
    }

    #[Test]
    public function a_thumbnail_is_generated_for_every_image(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('photo.jpg', 1000, 800)],
        ]));

        $image = Listing::firstOrFail()->images()->firstOrFail();

        $this->assertNotNull($image->thumb_path);
        Storage::disk('public')->assertExists($image->thumb_path);
    }

    #[Test]
    public function oversized_images_are_scaled_down(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('huge.jpg', 4000, 3000)],
        ]));

        $this->assertLessThanOrEqual(1600, Listing::firstOrFail()->images()->firstOrFail()->width);
    }

    #[Test]
    public function small_images_are_not_upscaled(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('small.jpg', 400, 300)],
        ]));

        $this->assertSame(400, Listing::firstOrFail()->images()->firstOrFail()->width);
    }

    #[Test]
    public function only_the_first_image_is_primary(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [
                UploadedFile::fake()->image('a.jpg', 600, 400),
                UploadedFile::fake()->image('b.jpg', 600, 400),
                UploadedFile::fake()->image('c.jpg', 600, 400),
            ],
        ]));

        $this->assertCount(1, Listing::firstOrFail()->images()->where('is_primary', true)->get());
    }

    #[Test]
    public function a_listing_can_be_published_without_any_image(): void
    {
        $this->post(route('listing.store'), $this->payload());

        $this->assertCount(0, Listing::firstOrFail()->images);
    }

    #[Test]
    public function non_image_files_are_rejected(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf')],
        ]))->assertSessionHasErrors('images.0');

        $this->assertSame(0, Listing::count());
    }

    #[Test]
    public function files_over_the_size_limit_are_rejected(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('big.jpg')->size(9000)],
        ]))->assertSessionHasErrors('images.0');
    }

    #[Test]
    public function tiny_images_are_rejected(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('icon.jpg', 50, 50)],
        ]))->assertSessionHasErrors('images.0');
    }

    #[Test]
    public function more_images_than_the_limit_are_rejected(): void
    {
        $images = [];

        for ($i = 0; $i <= ListingImageService::MAX_PER_LISTING; $i++) {
            $images[] = UploadedFile::fake()->image("img{$i}.jpg", 600, 400);
        }

        $this->post(route('listing.store'), $this->payload(['images' => $images]))
            ->assertSessionHasErrors('images');
    }

    #[Test]
    public function a_price_is_required_for_a_fixed_price_listing(): void
    {
        $this->post(route('listing.store'), $this->payload(['price_amount' => null]))
            ->assertSessionHasErrors('price_amount');
    }

    #[Test]
    public function an_on_request_listing_needs_no_price(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'price_type' => 'on_request',
            'price_amount' => null,
            'currency_code' => null,
        ]));

        $listing = Listing::firstOrFail();

        $this->assertNull($listing->price_amount);
        $this->assertNull($listing->price_in_base);
    }

    #[Test]
    public function deleting_an_image_removes_its_files_and_promotes_the_next(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [
                UploadedFile::fake()->image('a.jpg', 600, 400),
                UploadedFile::fake()->image('b.jpg', 600, 400),
            ],
        ]));

        $listing = Listing::firstOrFail();
        $primary = $listing->images()->where('is_primary', true)->firstOrFail();
        $path = $primary->path;

        app(ListingImageService::class)->delete($primary);

        Storage::disk('public')->assertMissing($path);
        $this->assertCount(1, $listing->fresh()->images()->where('is_primary', true)->get());
    }

    #[Test]
    public function reordering_moves_the_primary_flag(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'images' => [
                UploadedFile::fake()->image('a.jpg', 600, 400),
                UploadedFile::fake()->image('b.jpg', 600, 400),
            ],
        ]));

        $listing = Listing::firstOrFail();
        $ids = $listing->images()->orderBy('sort_order')->pluck('id')->all();

        app(ListingImageService::class)->reorder($listing, array_reverse($ids));

        $this->assertTrue($listing->images()->find($ids[1])->is_primary);
        $this->assertFalse($listing->images()->find($ids[0])->is_primary);
    }
}
PHP;

// ─── الاختبارَان الهشّان من السكربت السابق ───

$files['tests/Feature/Catalog/ListingRenewTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * التجديد.
 *
 * الاختبار الثاني هو حجر الزاوية: تجديد يحرّك published_at يحوّل زر
 * التجديد إلى ترقية مجانية، فيبدو إعلان عمره سنة جديداً ويفقد ترتيب
 * «الأحدث» معناه.
 */
class ListingRenewTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    /**
     * إرجاع الإعلان إلى ما بعد الانتهاء بالكتابة المباشرة.
     *
     * الصيغة السابقة كانت forceFill(['expires_at' => now()->subDay()]) على
     * الكائن نفسه بعد التجديد الأول — وهي هشّة: إن نُفِّذ السطران في نفس
     * الثانية فالقيمة الجديدة تساوي القيمة الأصلية المحفوظة في الكائن،
     * فلا يعدّها Eloquent «متغيّرة» ولا يكتب شيئاً، فيسقط الاختبار حسب
     * توقيت التشغيل. اختبار يسقط بالساعة أسوأ من اختبار يسقط دائماً:
     * الأول يُعلَّم «متقلّب» ويُتجاهل.
     */
    private function expireNow(Listing $listing): void
    {
        Listing::withoutEvents(fn () => Listing::query()
            ->whereKey($listing->getKey())
            ->update(['expires_at' => now()->subDays(2)]));
    }

    #[Test]
    public function an_expired_listing_comes_back_for_thirty_days(): void
    {
        $listing = $this->listingFor($this->owner, [
            'status' => 'active',
            'published_at' => now()->subDays(40),
            'expires_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertRedirect(route('account.listings', ['tab' => 'live']));

        $fresh = $listing->fresh();

        $this->assertTrue($fresh->expires_at->greaterThan(now()->addDays(29)));
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, $fresh->renewals_count);
        $this->assertNotNull($fresh->renewed_at);
    }

    #[Test]
    public function renewing_never_touches_the_publication_date(): void
    {
        $published = now()->subDays(40)->startOfSecond();

        $listing = $this->listingFor($this->owner, [
            'published_at' => $published,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->assertTrue($published->equalTo($listing->fresh()->published_at));
    }

    #[Test]
    public function a_listing_far_from_expiry_is_refused_with_a_reason(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->addDays(25)]);
        $before = $listing->expires_at;

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('error');

        $fresh = $listing->fresh();

        $this->assertTrue($before->equalTo($fresh->expires_at));
        $this->assertSame(0, $fresh->renewals_count);
    }

    #[Test]
    public function a_listing_inside_the_window_is_accepted(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->addDays(3)]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('status');

        $this->assertSame(1, $listing->fresh()->renewals_count);
    }

    #[Test]
    public function a_sold_listing_is_not_renewable(): void
    {
        $listing = $this->listingFor($this->owner, [
            'status' => 'sold',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('error');

        $this->assertSame('sold', $listing->fresh()->status);
    }

    #[Test]
    public function a_stranger_cannot_renew(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->subDay()]);

        $this->actingAs($this->stranger)
            ->post(route('account.listings.renew', $listing))
            ->assertForbidden();

        $this->assertSame(0, $listing->fresh()->renewals_count);
    }

    #[Test]
    public function renewals_accumulate_rather_than_reset(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->expireNow($listing);

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->assertSame(2, $listing->fresh()->renewals_count);
    }
}
PHP;

$files['tests/Feature/Catalog/ListingStatsTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingStatsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingStatsTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    private function storeDay(string $date, array $values = []): void
    {
        DB::table('listing_daily_stats')->insert([
            'listing_id' => $this->listing->id,
            'stat_date' => $date,
            'views' => 0, 'unique_viewers' => 0, 'contacts' => 0,
            'search_impressions' => 0, 'search_clicks' => 0,
            ...$values,
        ]);
    }

    /**
     * الأرقام تُفحص على القارئ لا على HTML الصفحة: assertSee('20') على
     * صفحة تحمل تواريخ مثل 2026-08-10 ينجح بلا أي علاقة بالمجموع.
     */
    #[Test]
    public function the_reader_totals_only_the_stored_days(): void
    {
        $this->storeDay(now()->toDateString(), ['views' => 137, 'unique_viewers' => 41]);
        $this->storeDay(now()->subDay()->toDateString(), ['views' => 66, 'unique_viewers' => 9]);

        $stats = app(ListingStatsReader::class)->forListing($this->listing);

        $this->assertSame(203, $stats['totals']['views']);
        $this->assertSame(50, $stats['totals']['unique_viewers']);
        $this->assertCount(ListingStatsReader::DEFAULT_DAYS, $stats['rows']);
    }

    /** يوم خارج النافذة لا يُحسب — وإلا صار «آخر 14 يوماً» عنواناً كاذباً */
    #[Test]
    public function a_day_outside_the_window_is_excluded(): void
    {
        $this->storeDay(now()->subDays(40)->toDateString(), ['views' => 500]);

        $this->assertSame(0, app(ListingStatsReader::class)->forListing($this->listing)['totals']['views']);
    }

    #[Test]
    public function the_owner_sees_the_daily_numbers_on_the_page(): void
    {
        $this->storeDay(now()->toDateString(), ['views' => 137, 'search_impressions' => 412]);

        $this->actingAs($this->owner)
            ->get(route('account.listings.stats', $this->listing))
            ->assertOk()
            ->assertSee('137')
            ->assertSee('412');
    }

    #[Test]
    public function a_stranger_cannot_read_someone_elses_numbers(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('account.listings.stats', $this->listing))
            ->assertForbidden();
    }

    #[Test]
    public function an_empty_rollup_says_so_instead_of_showing_bare_zeros(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account.listings.stats', $this->listing))
            ->assertOk()
            ->assertSee('analytics:rollup');
    }

    #[Test]
    public function the_owners_own_visit_is_not_counted(): void
    {
        $this->actingAs($this->owner)
            ->get(route('listing.show', $this->listing))
            ->assertOk();

        $this->assertSame(0, (int) $this->listing->fresh()->views_count);
    }

    #[Test]
    public function a_visitors_view_is_counted(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('listing.show', $this->listing))
            ->assertOk();

        $this->assertSame(1, (int) $this->listing->fresh()->views_count);
    }

    #[Test]
    public function the_owner_may_preview_a_paused_listing(): void
    {
        $this->listing->forceFill(['status' => 'paused'])->save();

        $this->actingAs($this->owner)->get(route('listing.show', $this->listing))->assertOk();
    }

    /**
     * اختبار منفصل لا سطر ثانٍ في الاختبار السابق.
     *
     * actingAs يثبّت المستخدم لبقية دالة الاختبار كلها، فطلبٌ «كزائر»
     * بعد actingAs يبقى مسجَّلاً — والاختبار كان يقيس المالك مرتين
     * ويظن أنه قاس الزائر. الفصل هو الإصلاح الصحيح، لا Auth::logout
     * في المنتصف: حالتان مختلفتان تستحقان اسمين مختلفين.
     */
    #[Test]
    public function a_visitor_may_not_see_a_paused_listing(): void
    {
        $this->listing->forceFill(['status' => 'paused'])->save();

        $this->get(route('listing.show', $this->listing))->assertNotFound();
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (2) البحث
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Search/DTO/SearchCriteria.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\DTO;

use App\Modules\Shared\Models\Currency;
use Illuminate\Http\Request;

/**
 * معايير البحث كوحدة واحدة غير قابلة للتغيير.
 *
 * الثغرة المُسدَّدة هنا: كل القراءات كانت تمر بـ$request->string()، وهي
 * تُمرِّر القيمة إلى Stringable الذي يحوّلها بـ(string). والرابط
 * ?q[]=x يعطي مصفوفة، فـ(string)array يُطلق تحذير PHP، ولارافل يحوّل
 * التحذيرات إلى ErrorException — فيسقط أي أحد الصفحة الرئيسية بخطأ 500
 * برابط واحد بلا حساب ولا أدوات. وهذا بالضبط ما تعِد به تعليقات هذا
 * الملف نفسه: «رابط يكتبه أي أحد لا يجب أن يُسقط صفحة».
 *
 * الحلّ: قراءة قياسية واحدة تُهمل كل ما ليس قيمة مفردة. القوائم البيضاء
 * الموجودة تحمي القيم، وهذه تحمي *النوع* — وهما حاجتان مختلفتان.
 */
final readonly class SearchCriteria
{
    public const SORTS = ['newest', 'oldest', 'price_asc', 'price_desc'];

    public const CONDITIONS = ['new', 'like_new', 'used', 'for_parts'];

    public const SALE_TYPES = ['direct', 'auction'];

    /**
     * سقف طول الاستعلام.
     *
     * سقف الكلمات (8 في SearchTokenizer) لا يكفي: العبارة الكاملة تُستخدم
     * كنمط LIKE واحد في ترتيب الصلة، فاستعلام بطول 50 كيلوبايت يصبح نمط
     * LIKE بطول 50 كيلوبايت على كل صف في الجدول. 120 محرفاً أطول من أي
     * بحث حقيقي بمراحل.
     */
    public const MAX_QUERY_LENGTH = 120;

    public function __construct(
        public ?string $query = null,
        public ?int $categoryId = null,
        public ?int $locationId = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        /** العملة التي كتب المستخدم النطاق السعري بها — ليست عملة الإعلان */
        public string $priceCurrency = 'YER',
        public ?string $listingCurrency = null,
        public ?string $condition = null,
        public ?string $saleType = null,
        public bool $withImageOnly = false,
        public string $sort = 'newest',
        public int $perPage = 24,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $currencies = Currency::activeCodes();

        [$min, $max] = self::readRange($request);

        return new self(
            query: self::text($request, 'q'),
            categoryId: self::id($request, 'category'),
            locationId: self::id($request, 'location'),
            priceMin: $min,
            priceMax: $max,
            priceCurrency: self::pick(self::upper($request, 'currency'), $currencies)
                ?? Currency::baseCode(),
            listingCurrency: self::pick(self::upper($request, 'listing_currency'), $currencies),
            condition: self::pick(self::scalar($request, 'condition'), self::CONDITIONS),
            saleType: self::pick(self::scalar($request, 'type'), self::SALE_TYPES),
            withImageOnly: self::flag($request, 'with_image'),
            sort: self::pick(self::scalar($request, 'sort'), self::SORTS) ?? 'newest',
            perPage: min(max(self::id($request, 'per_page') ?? 24, 6), 60),
        );
    }

    // ---- قراءة المدخلات: النوع أولاً، ثم القيمة ----

    /** قيمة مفردة أو null — مصفوفة أو كائن يُهمَل بلا خطأ */
    private static function scalar(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function upper(Request $request, string $key): ?string
    {
        $value = self::scalar($request, $key);

        return $value === null ? null : mb_strtoupper($value);
    }

    /** نص البحث: مقصوص بحدّ الطول قبل أن يصل إلى أي استعلام */
    private static function text(Request $request, string $key): ?string
    {
        $value = self::scalar($request, $key);

        return $value === null ? null : mb_substr($value, 0, self::MAX_QUERY_LENGTH);
    }

    private static function id(Request $request, string $key): ?int
    {
        $value = self::scalar($request, $key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function flag(Request $request, string $key): bool
    {
        return in_array(self::scalar($request, $key), ['1', 'true', 'on', 'yes'], true);
    }

    /** القيمة إن كانت في القائمة البيضاء، وإلا null */
    private static function pick(?string $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * حدود النطاق السعري.
     *
     * السالب يُهمَل، والحدّان المنقلبان يُعكسان بدل إرجاع صفر نتائج.
     * وغير الرقمي يُهمَل: (float)'abc' كان يعطي 0.0 فيتحوّل «من abc»
     * إلى «من صفر» — حدّ لم يطلبه أحد.
     */
    private static function readRange(Request $request): array
    {
        $min = self::amount($request, 'min');
        $max = self::amount($request, 'max');

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    private static function amount(Request $request, string $key): ?float
    {
        $value = self::scalar($request, $key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount < 0 ? null : $amount;
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && $this->query !== '';
    }

    public function hasPriceFilter(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }

    /** يُستخدم كمفتاح تسجيل في وحدة التحليلات */
    public function activeFilterKeys(): array
    {
        return array_keys(array_filter([
            'category' => $this->categoryId !== null,
            'location' => $this->locationId !== null,
            'price' => $this->hasPriceFilter(),
            'currency' => $this->listingCurrency !== null,
            'condition' => $this->condition !== null,
            'sale_type' => $this->saleType !== null,
            'with_image' => $this->withImageOnly,
        ]));
    }
}
PHP;

$files['app/Modules/Search/Services/SearchTokenizer.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\Services;

use App\Modules\Shared\Services\ArabicNormalizer;

/**
 * تقطيع استعلام البحث إلى كلمات ذات قيمة تمييزية.
 *
 * لماذا صنف منفصل ولم تُضَف كلمات الوقف إلى ArabicNormalizer:
 * الـNormalizer يكتب الأعمدة المخزَّنة. لو حذف كلمات الوقف من النص
 * المخزَّن، فعنوان «شقة للإيجار في حدة» يُخزَّن ناقصاً وتنكسر مطابقة
 * العبارة الكاملة بلا سبيل لاستعادتها إلا بإعادة فهرسة. حذف كلمات
 * الوقف قرار خاص بالاستعلام لا بالتخزين.
 */
final class SearchTokenizer
{
    /**
     * كلمات وظيفية بلا قيمة تمييزية — بصيغتها بعد التطبيع.
     *
     * ما استُثني بقصد: «للبيع» و«للايجار» و«مطلوب» و«مستعمل» و«جديد».
     * حذفها يجعل «شقة للإيجار» تطابق «شقة للبيع» — وهذا في العقار خطأ
     * فادح لا تحسين. القاعدة: تُحذف الكلمة إن كانت بلا معنى، لا إن
     * كانت شائعة.
     */
    private const STOP_WORDS = [
        'في', 'من', 'الي', 'علي', 'عن', 'مع', 'او', 'ثم', 'حتي',
        'ان', 'انه', 'اني', 'كان', 'كانت', 'يكون', 'ايضا', 'جدا',
        'هذا', 'هذه', 'ذلك', 'تلك', 'الذي', 'التي', 'الذين',
        'هو', 'هي', 'هم', 'انا', 'نحن', 'انت',
        'له', 'لها', 'به', 'بها', 'فيه', 'فيها', 'عليه', 'عليها',
        'عند', 'لدي', 'بعد', 'قبل', 'كل', 'بعض', 'اي', 'ما', 'لا',
        'قد', 'هل', 'يوجد', 'توجد', 'وايضا', 'ولكن', 'لكن',
    ];

    /**
     * سقف عدد الكلمات: كل كلمة تُترجم إلى شرط LIKE على استعلام لا
     * يستخدم فهرساً أصلاً. صفحة عامة لا يجوز أن يُبطئها زائر بجملة.
     */
    private const MAX_TOKENS = 8;

    /**
     * سقف طول العبارة.
     *
     * SearchCriteria تقصّ الاستعلام عند 120 محرفاً، وهذا سقف ثانٍ أضيق
     * للعبارة وحدها. ولماذا سقفان: الأول يحمي كل شيء يلمس النص، وهذا
     * يحمي نمط LIKE بالتحديد — والصنف الذي يبني النمط هو المسؤول عن
     * حدّه، لا الصنف الذي يقرأ الطلب. حماية على مستوى واحد تسقط أول
     * ما يُستدعى المُقطِّع من مكان آخر (أمر artisan، اختبار، واجهة API).
     */
    private const MAX_PHRASE = 80;

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
    ) {
    }

    /** @return array<int, string> */
    public function tokens(?string $query): array
    {
        $all = $this->normalizer->tokenize($query);

        if ($all === []) {
            return [];
        }

        $meaningful = array_values(array_filter(
            $all,
            fn (string $token): bool => ! in_array($token, self::STOP_WORDS, true),
        ));

        // استعلام كامله كلمات وقف («هل يوجد») يبقى كما هو: لو أرجعنا
        // مصفوفة فارغة لسقط شرط النص كلياً وعادت كل الإعلانات
        if ($meaningful === []) {
            $meaningful = $all;
        }

        return array_slice($meaningful, 0, self::MAX_TOKENS);
    }

    /**
     * الاستعلام كعبارة واحدة مطبّعة — تُستخدم لأعلى مستوى صلة.
     * كلمات الوقف تبقى فيها: العبارة الحرفية هي ما كتبه المستخدم.
     */
    public function phrase(?string $query): string
    {
        return mb_substr($this->normalizer->normalize($query), 0, self::MAX_PHRASE);
    }
}
PHP;

$files['app/Modules/Search/Services/SynonymExpander.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع كلمة الاستعلام إلى مجموعة مكافئاتها.
 *
 * هذا هو «المستوى الثاني» من الترتيب المطلوب: المطابقة التامة أولاً، ثم
 * المكافئ. جدول search_synonyms كان في المخطط من أول هجرة ولا سطر في
 * المشروع يقرأه — وعد في البنية بلا تنفيذ.
 *
 * لماذا يهمّ في اليمن تحديداً: الناس يكتبون «موتر» و«عربية» و«سيارة»
 * للشيء نفسه، و«دباب» للدراجة النارية، و«تلفون» و«جوال» و«موبايل».
 * بلا مكافئات، البحث يعاقب من كتب بلهجته.
 *
 * ═══ قرارات ═══
 *
 * 1. المجموعات تُبنى بالإغلاق الترابطي لا بالأزواج المباشرة.
 *
 *    الجدول يخزّن أزواجاً: (جوال، موبايل) و(جوال، تلفون) و(جوال، هاتف).
 *    القراءة الزوجية المباشرة تجعل «جوال» يجد الثلاثة، بينما «تلفون»
 *    يجد «جوال» فقط ولا يجد «موبايل» — فيعمل البحث لمن كتب الكلمة
 *    المركزية ويفشل لمن كتب طرفاً. والأسوأ أن العيب غير متناظر، فيبدو
 *    عشوائياً ويستحيل تفسيره من قراءة الجدول.
 *
 *    الإغلاق يجمع كل كلمات السلسلة في مجموعة واحدة، فتتكافأ الأطراف.
 *    والثمن معروف: سلسلة أ↔ب وب↔ج تجعل «أ» و«ج» مكافئين ولو لم يقصد
 *    من أدخلهما ذلك. لهذا سقف حجم المجموعة أدناه، ولهذا تبقى قاعدة
 *    البذرة صريحة: يُدخَل الزوج إن كان الطرفان الشيء نفسه، لا إن
 *    كانا يظهران معاً.
 *
 * 2. الحفظ في الذاكرة لطلب واحد، لا في Cache دائم.
 *    الجدول عشرات الصفوف؛ استعلام واحد لكل طلب لا يُقاس. أما Cache
 *    بعمر ساعة فيعني أن إضافة مكافئ لا تظهر — فيظن من أضافه أن الميزة
 *    معطوبة ويقضي وقته في المكان الخطأ. الكاش يُضاف حين يُقاس أثره.
 *
 * 3. سقفان: على عدد المكافئات لكل كلمة، وعلى حجم المجموعة كلها.
 *    ثماني كلمات × خمسة مكافئات = أربعون شرط LIKE على مسح كامل للجدول.
 */
final class SynonymExpander
{
    public const MAX_PER_TOKEN = 3;

    /** سلسلة أطول من هذا تعني على الأرجح خطأ إدخال، لا عائلة كلمات */
    public const MAX_GROUP = 6;

    /** @var array<string, array<int, string>>|null */
    private ?array $groups = null;

    /**
     * كل كلمة تصبح مجموعة: الكلمة نفسها أولاً ثم مكافئاتها.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, array<int, string>>
     */
    public function expand(array $tokens): array
    {
        $result = [];

        foreach ($tokens as $token) {
            $family = $this->groups()[$token] ?? [];

            $synonyms = array_slice(
                array_values(array_filter($family, fn (string $word): bool => $word !== $token)),
                0,
                self::MAX_PER_TOKEN
            );

            $result[] = array_values(array_unique([$token, ...$synonyms]));
        }

        return $result;
    }

    /**
     * لكل كلمة: عائلتها كاملة.
     *
     * @return array<string, array<int, string>>
     */
    private function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        // الجدول قد يغيب في قاعدة لم تُهجَّر بعد — والبحث يجب أن يعمل بلا
        // مكافئات لا أن يسقط. المكافئات تحسين، لا شرط تشغيل.
        if (! Schema::hasTable('search_synonyms')) {
            return $this->groups = [];
        }

        /** @var array<string, array<int, string>> $families كل كلمة ← مرجع عائلتها */
        $families = [];

        foreach (DB::table('search_synonyms')->where('is_active', true)->get() as $row) {
            $this->merge($families, (string) $row->term, (string) $row->synonym);
        }

        return $this->groups = array_map(
            fn (array $family): array => array_slice($family, 0, self::MAX_GROUP),
            $families
        );
    }

    /**
     * دمج عائلتي كلمتين في واحدة، وتحديث كل أعضائها.
     *
     * الصيغة بسيطة عن قصد: البديل هو بنية union-find كاملة، وهي الحل
     * الصحيح لملايين الأزواج ومبالغة واضحة لعشرات. حين يكبر الجدول
     * تُستبدل هذه الدالة وحدها.
     *
     * @param  array<string, array<int, string>>  $families
     */
    private function merge(array &$families, string $a, string $b): void
    {
        if ($a === '' || $b === '' || $a === $b) {
            return;
        }

        $merged = array_values(array_unique([
            ...($families[$a] ?? [$a]),
            ...($families[$b] ?? [$b]),
        ]));

        foreach ($merged as $word) {
            $families[$word] = $merged;
        }
    }
}
PHP;

$files['app/Modules/Search/Database/Seeders/SynonymSeeder.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\Database\Seeders;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * مكافئات اللهجة اليمنية.
 *
 * القاعدة الحاكمة، وهي نفس قاعدة كلمات الوقف: المكافئ يجمع كلمتين
 * تعنيان الشيء نفسه، لا كلمتين تظهران معاً.
 *
 * ما رُفض إدخاله بقصد:
 *   · بيع ↔ ايجار        — عكسان لا مكافئان؛ خطأ فادح في العقار
 *   · وكاله ↔ جديد       — «وكالة» تعني قريب من الجديد، لا جديداً
 *   · سياره ↔ باص        — فئتان مختلفتان من المشترين
 *   · شقه ↔ بيت          — سعران مختلفان ونيّتان مختلفتان
 *
 * الكلمات تُخزَّن مطبّعة بنفس ArabicNormalizer الذي يبني search_blob،
 * وإلا فلن تطابق شيئاً: «سيارة» في الجدول لا تساوي «سياره» في العمود.
 */
class SynonymSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string}> */
    private const PAIRS = [
        ['سيارة', 'عربية'],
        ['سيارة', 'موتر'],
        ['دراجة', 'دباب'],
        ['دباب', 'موتوسيكل'],
        ['جوال', 'موبايل'],
        ['جوال', 'تلفون'],
        ['جوال', 'هاتف'],
        ['لابتوب', 'حاسوب'],
        ['لابتوب', 'كمبيوتر'],
        ['تلفزيون', 'شاشة'],
        ['ثلاجة', 'براد'],
        ['مكيف', 'سبليت'],
        ['عفش', 'أثاث'],
        ['عفش', 'موبيليا'],
        ['أرض', 'قطعة'],
        ['محل', 'دكان'],
        ['مطبخ', 'كبت'],
        ['كاميرا', 'كامرة'],
        ['ساعة', 'ساعات'],
        ['قطع غيار', 'سبيرات'],
    ];

    public function run(): void
    {
        $normalizer = app(ArabicNormalizer::class);
        $rows = [];

        foreach (self::PAIRS as [$term, $synonym]) {
            $term = $normalizer->normalize($term);
            $synonym = $normalizer->normalize($synonym);

            if ($term === '' || $synonym === '' || $term === $synonym) {
                continue;
            }

            // المفتاح الفريد (term, synonym) — والعلاقة تُقرأ متبادلة
            // في SynonymExpander، فصف واحد لكل زوج يكفي
            $rows[$term . '|' . $synonym] = [
                'term' => $term,
                'synonym' => $synonym,
                'weight' => 1,
                'is_active' => true,
            ];
        }

        DB::table('search_synonyms')->upsert(
            array_values($rows),
            ['term', 'synonym'],
            ['weight', 'is_active']
        );
    }
}
PHP;

$files['database/seeders/DatabaseSeeder.php'] = <<<'PHP'
<?php

namespace Database\Seeders;

use App\Modules\Catalog\Database\Seeders\ListingSeeder;
use App\Modules\Search\Database\Seeders\SynonymSeeder;
use App\Modules\Shared\Database\Seeders\CategorySeeder;
use App\Modules\Shared\Database\Seeders\CurrencySeeder;
use App\Modules\Shared\Database\Seeders\LocationSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            LocationSeeder::class,
            CategorySeeder::class,
            SynonymSeeder::class,
            ListingSeeder::class,
        ]);
    }
}
PHP;

$files['app/Modules/Search/Services/SearchQueryBuilder.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * محرك البحث.
 *
 * القرار المحوري: النطاق السعري الذي يكتبه المستخدم يُحوَّل مرة واحدة
 * إلى عملة الأساس، ثم يُقارن بعمود price_in_base المفهرس. البديل الخاطئ
 * الشائع — تحويل سعر كل إعلان أثناء الاستعلام — يمنع استخدام الفهرس
 * ويجعل البحث يبطؤ خطياً مع عدد الإعلانات.
 *
 * ═══ الثغرة المُسدَّدة في هذه النسخة ═══
 *
 * كانت المطابقة اشتراطاً صارماً لكل كلمة: كلمة واحدة غائبة تعني صفر
 * نتائج. و«تويوتا كورولا 2018 اوتوماتيك صنعاء» استعلام واقعي تماماً،
 * وكفيل بأن يُرجع صفراً حتى مع وجود عشرين كورولا في صنعاء — لأن
 * «اوتوماتيك» لم يكتبها المعلن.
 *
 * البنية الآن ثلاث درجات، بنفس الترتيب المطلوب:
 *
 *   1. تام    كل كلمة موجودة (بذاتها أو بمكافئها)
 *   2. قريب   إن أعطت الدرجة الأولى صفراً: كلمة واحدة تكفي، والترتيب
 *             بعدد الكلمات المطابقة تنازلياً
 *   3. رواج   ليس بعد — يحتاج بيانات سلوك حقيقية، وهو المرحلة التالية
 *
 * والدرجة الثانية لا تعمل إلا عند فشل الأولى. البديل المرفوض: تشغيلها
 * دائماً والاكتفاء بالترتيب. مرفوض لأن مطابقة كلمة واحدة من أربع تعني
 * صفحات من ضجيج فوق النتائج الصحيحة، وثقة المستخدم بالنتيجة أغلى من
 * تغطية إضافية لا يريدها.
 *
 * الكلفة: بحث بصفر نتائج يمسح الجدول مرتين بدل مرة. مقبول لأنه الحالة
 * التي يخرج فيها المستخدم من المنصة إن لم نُصلحها.
 */
final class SearchQueryBuilder
{
    public const MODE_STRICT = 'strict';

    public const MODE_LOOSE = 'loose';

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SynonymExpander $synonyms,
        private readonly ExchangeRateService $rates,
    ) {
    }

    /**
     * البحث بدرجاته.
     *
     * @return array{listings: LengthAwarePaginator, mode: string}
     *         mode ∈ browse | exact | near | none
     */
    public function search(SearchCriteria $criteria): array
    {
        if (! $criteria->hasQuery()) {
            return [
                'listings' => $this->paginate($criteria, self::MODE_STRICT),
                'mode' => 'browse',
            ];
        }

        $strict = $this->paginate($criteria, self::MODE_STRICT);

        if ($strict->total() > 0) {
            return ['listings' => $strict, 'mode' => 'exact'];
        }

        $loose = $this->paginate($criteria, self::MODE_LOOSE);

        return [
            'listings' => $loose,
            'mode' => $loose->total() > 0 ? 'near' : 'none',
        ];
    }

    public function paginate(SearchCriteria $criteria, string $mode = self::MODE_STRICT): LengthAwarePaginator
    {
        return $this->build($criteria, $mode)
            ->paginate($criteria->perPage)
            ->withQueryString();
    }

    public function count(SearchCriteria $criteria, string $mode = self::MODE_STRICT): int
    {
        return $this->build($criteria, $mode)->count();
    }

    public function build(SearchCriteria $criteria, string $mode = self::MODE_STRICT): Builder
    {
        $query = Listing::query()
            ->published()
            ->with(['currency', 'category', 'location', 'images']);

        $this->applyText($query, $criteria, $mode);
        $this->applyPrice($query, $criteria);
        $this->applyFacets($query, $criteria);
        $this->applySort($query, $criteria, $mode);

        return $query;
    }

    // ---- النص ----

    /**
     * المطابقة تجري على search_blob وحده.
     *
     * كانت على title_normalized وdescription_normalized، فكان «شقة في
     * حدة» يفشل لأن اسم المديرية مفتاح أجنبي لا نص. والعمود المجمّع يضم
     * العنوان والوصف والتصنيف والموقع والمحافظة.
     *
     * نستخدم LIKE لا MATCH لأن محلل MySQL الافتراضي لا يفهم سوابق
     * ولواحق العربية، فيفوّت «بالسيارة» عند البحث عن «سيارة».
     */
    private function applyText(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        $groups = $this->groups($criteria);

        if ($groups === []) {
            return;
        }

        if ($mode === self::MODE_STRICT) {
            // كل مجموعة شرط لازم، وداخل المجموعة الكلمة أو أحد مكافئاتها
            $query->where(function (Builder $outer) use ($groups): void {
                foreach ($groups as $group) {
                    $outer->where(function (Builder $inner) use ($group): void {
                        foreach ($group as $variant) {
                            $inner->orWhere('search_blob', 'like', "%{$variant}%");
                        }
                    });
                }
            });

            return;
        }

        // الدرجة الثانية: مجموعة واحدة تكفي — والترتيب هو ما يفصل
        $query->where(function (Builder $outer) use ($groups): void {
            foreach ($groups as $group) {
                foreach ($group as $variant) {
                    $outer->orWhere('search_blob', 'like', "%{$variant}%");
                }
            }
        });
    }

    /** @return array<int, array<int, string>> */
    private function groups(SearchCriteria $criteria): array
    {
        if (! $criteria->hasQuery()) {
            return [];
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        return $tokens === [] ? [] : $this->synonyms->expand($tokens);
    }

    // ---- السعر: جوهر المشروع ----

    private function applyPrice(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasPriceFilter()) {
            return;
        }

        // تحويل واحد لحدود النطاق — لا تحويل لكل إعلان
        $range = $this->rates->convertRange(
            $criteria->priceMin,
            $criteria->priceMax,
            $criteria->priceCurrency,
        );

        $query->priceBetween($range['min'], $range['max']);

        // الإعلانات بلا سعر لا يصح ظهورها عند الفلترة بالسعر
        $query->whereNotNull('price_in_base');
    }

    // ---- بقية الفلاتر ----

    private function applyFacets(Builder $query, SearchCriteria $criteria): void
    {
        if ($criteria->categoryId !== null) {
            $category = Category::find($criteria->categoryId);

            if ($category !== null) {
                $query->inCategory($category);
            }
        }

        if ($criteria->locationId !== null) {
            $location = Location::find($criteria->locationId);

            if ($location !== null) {
                $query->inLocation($location);
            }
        }

        // فلتر عملة الإعلان — منفصل تماماً عن عملة النطاق السعري
        if ($criteria->listingCurrency !== null) {
            $query->where('currency_code', $criteria->listingCurrency);
        }

        if ($criteria->condition !== null) {
            $query->where('condition_type', $criteria->condition);
        }

        if ($criteria->saleType !== null) {
            $query->where('sale_type', $criteria->saleType);
        }

        if ($criteria->withImageOnly) {
            $query->whereHas('images');
        }
    }

    // ---- الترتيب ----

    private function applySort(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        // المميّزة أولاً — والنطاق يتحقق من سريان الترقية لا من العلم وحده
        $query->featuredFirst();

        // في الدرجة الثانية يتقدّم عدد الكلمات المطابقة على كل ما بعده:
        // النتيجة التي طابقت ثلاث كلمات أقرب حتماً من التي طابقت واحدة
        if ($mode === self::MODE_LOOSE) {
            $this->applyMatchCount($query, $criteria);
        }

        $this->applyRelevance($query, $criteria);

        match ($criteria->sort) {
            // الترتيب بالسعر يجري على القيمة الموحّدة —
            // وإلا لظهر إعلان بـ100 ريال يمني قبل إعلان بـ90 دولاراً
            'price_asc' => $query->orderByRaw('price_in_base IS NULL')->orderBy('price_in_base'),
            'price_desc' => $query->orderByRaw('price_in_base IS NULL')->orderByDesc('price_in_base'),
            'oldest' => $query->orderBy('published_at'),
            default => $query->orderByDesc('published_at'),
        };
    }

    /** عدد مجموعات الكلمات التي طابقت النص المجمّع */
    private function applyMatchCount(Builder $query, SearchCriteria $criteria): void
    {
        $groups = $this->groups($criteria);

        if ($groups === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($groups as $group) {
            $conditions = [];

            foreach ($group as $variant) {
                $conditions[] = 'search_blob like ?';
                $bindings[] = '%' . $variant . '%';
            }

            $cases[] = 'case when (' . implode(' or ', $conditions) . ') then 1 else 0 end';
        }

        $query->orderByRaw('(' . implode(' + ', $cases) . ') desc', $bindings);
    }

    /**
     * إشارتا الصلة، بهذا الترتيب:
     *
     *   1. العبارة الكاملة كما كتبها المستخدم موجودة في العنوان
     *   2. عدد كلمات الاستعلام الموجودة في العنوان
     *
     * ثم يكسر التعادل الترتيب المطلوب (الأحدث افتراضاً).
     *
     * لماذا عدّ لا اشتراط كل الكلمات: اسم المدينة لا يكون في العنوان
     * أبداً — هو في النص المجمّع. فأي استعلام من شكل «سلعة + مدينة»،
     * وهو أشيع أشكال البحث في اليمن، كان يُسقط كل النتائج إلى مستوى
     * واحد فيعود الترتيب إلى التاريخ وحده.
     *
     * ولماذا يبقى هذا مقبولاً بعد رفض المجموع المرجّح (0.6×صلة +
     * 0.4×رواج): العدّ ليس وزناً. «عنوانه طابق كلمتين من كلماتك» جملة
     * يفهمها البائع؛ «درجته 0.73» ليست كذلك.
     *
     * وهنا العنوان وحده لا النص المجمّع، عن قصد: العنوان ما اختاره
     * المعلن ليصف السلعة، والنص المجمّع يضم اسم المحافظة والتصنيف —
     * فلو عُدَّ عليه لتساوت كل إعلانات صنعاء في الصلة.
     */
    private function applyRelevance(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasQuery() || $criteria->sort !== 'newest') {
            return;
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        if ($tokens === []) {
            return;
        }

        $phrase = $this->tokenizer->phrase($criteria->query);

        if ($phrase !== '') {
            $query->orderByRaw(
                'case when title_normalized like ? then 1 else 0 end desc',
                ['%' . $phrase . '%']
            );
        }

        $hits = implode(
            ' + ',
            array_fill(0, count($tokens), 'case when title_normalized like ? then 1 else 0 end')
        );

        $query->orderByRaw(
            "({$hits}) desc",
            array_map(fn (string $token): string => '%' . $token . '%', $tokens)
        );
    }

    // ---- بيانات مساعدة للواجهة ----

    /**
     * توزيع النتائج على العملات — يُعرض بجانب فلتر العملة.
     *
     * reorder() ضرورية: تُزيل ترتيب الصلة وارتباطاته، وGROUP BY لا يقبل
     * ترتيباً بتعبير على عمود غير مجمَّع.
     *
     * والدرجة تُمرَّر من المتحكّم: لو حُسب التوزيع دائماً بالدرجة الأولى
     * لعرض «0 نتيجة بالريال» فوق صفحة مليئة بنتائج الدرجة الثانية —
     * رقمان متناقضان في شاشة واحدة.
     */
    public function currencyBreakdown(SearchCriteria $criteria, string $mode = self::MODE_STRICT): array
    {
        return $this->build($criteria, $mode)
            ->reorder()
            ->select('currency_code', DB::raw('count(*) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->all();
    }
}
PHP;

$files['app/Modules/Search/Http/Controllers/SearchController.php'] = <<<'PHP'
<?php

namespace App\Modules\Search\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController
{
    public function __construct(
        private readonly SearchQueryBuilder $search,
        private readonly ExchangeRateService $rates,
        private readonly ArabicNormalizer $normalizer,
        private readonly EventRecorder $events,
    ) {}

    public function __invoke(Request $request): View
    {
        $criteria = SearchCriteria::fromRequest($request);

        // القياس حول الاستعلام نفسه فقط — لا حول تجهيز القوالب
        $startedAt = microtime(true);
        $result = $this->search->search($criteria);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $listings = $result['listings'];
        $mode = $result['mode'];

        $this->recordSearch($criteria, $listings->total(), $durationMs, $mode);
        $this->recordFilters($criteria, $listings->total());
        $this->recordImpressions($listings);

        return view('search::index', [
            'criteria' => $criteria,
            'listings' => $listings,
            'matchMode' => $mode,
            'breakdown' => $this->breakdown($criteria, $listings, $mode),
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->get(),
            'rateMap' => $this->rates->currentRates(),
            'baseCurrency' => Currency::base(),
        ]);
    }

    /**
     * توزيع العملات يُحسب بنفس درجة المطابقة التي أنتجت النتائج، ويُتخطّى
     * كلياً عند صفر نتائج.
     *
     * كان يُحسب دائماً، أي مسح كامل ثالث للجدول في كل بحث — واحد للنتائج،
     * وواحد لعدّاد الترقيم، وواحد للتوزيع. وعند صفر نتائج كانت الثلاثة
     * تُنفَّذ لتعرض شريطاً فارغاً لا يقرأه أحد.
     */
    private function breakdown(SearchCriteria $criteria, LengthAwarePaginator $listings, string $mode): array
    {
        if ($listings->total() === 0) {
            return [];
        }

        return $this->search->currencyBreakdown(
            $criteria,
            $mode === 'near' ? SearchQueryBuilder::MODE_LOOSE : SearchQueryBuilder::MODE_STRICT
        );
    }

    /**
     * حدث البحث.
     *
     * يُسجَّل فقط عند وجود نية بحث فعلية — تصفّح الصفحة الأولى بلا فلاتر
     * ليس بحثاً، وتسجيله يُفسد كل إحصاءات «الاستعلامات بصفر نتائج».
     *
     * وmatch_mode أُضيف إلى الحمولة لأنه أهم رقم تشخيصي في المحرك:
     * نسبة الاستعلامات التي فشلت مطابقتها التامة هي المقياس المباشر
     * لجودة النص المجمّع والمكافئات. بلا تسجيله، التراجع إلى «الأقرب»
     * يعمل بصمت ولا نعرف كم مرة احتاجه الناس.
     */
    private function recordSearch(SearchCriteria $criteria, int $total, int $durationMs, string $mode): void
    {
        if (! $criteria->hasQuery() && ! $criteria->hasPriceFilter()) {
            return;
        }

        $this->events->record(EventName::SearchPerformed, payload: [
            // النص المطبّع فقط: التجميع يعمل صحيحاً، والنص الأصلي لا يُحفظ
            'q_normalized' => $this->normalizer->normalize($criteria->query),
            'results' => $total,
            'duration_ms' => $durationMs,
            'match_mode' => $mode,
            'filters' => $criteria->activeFilterKeys(),
            'price_currency' => $criteria->hasPriceFilter() ? $criteria->priceCurrency : null,
            'sort' => $criteria->sort,
        ]);
    }

    /** حدث لكل فلتر مُطبَّق — منه تُحسب الفلاتر المُربكة */
    private function recordFilters(SearchCriteria $criteria, int $total): void
    {
        foreach ($criteria->activeFilterKeys() as $key) {
            $this->events->record(EventName::FilterApplied, payload: [
                'key' => $key,
                'results_after' => $total,
            ]);
        }
    }

    /** ظهور كل نتيجة مع موضعها — البسط في حساب نسبة النقر */
    private function recordImpressions(LengthAwarePaginator $listings): void
    {
        if ($listings->isEmpty()) {
            return;
        }

        $offset = ($listings->firstItem() ?? 1) - 1;

        $this->events->recordImpressions($listings->pluck('id')->all(), $offset);
    }
}
PHP;

$files['app/Modules/Search/Resources/Views/index.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', $criteria->hasQuery() ? "نتائج البحث عن {$criteria->query}" : 'تصفح الإعلانات')

@php $matchMode = $matchMode ?? 'browse'; @endphp

@section('content')

<form method="GET" action="{{ route('search') }}"
      x-data="priceFilter(@js($rateMap), @js($criteria->priceCurrency), @js($baseCurrency->symbol))">

    {{-- شريط البحث النصي --}}
    <div class="flex gap-2">
        <label class="sr-only" for="q">كلمة البحث</label>
        <input id="q" name="q" type="search" value="{{ $criteria->query }}"
               maxlength="{{ \App\Modules\Search\DTO\SearchCriteria::MAX_QUERY_LENGTH }}"
               placeholder="ابحث عن سيارة، شقة، جوال…"
               class="min-w-0 flex-1 rounded-xl border border-line bg-white px-4 py-3 text-base placeholder:text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">

        <button type="submit"
                class="rounded-xl bg-ink px-5 py-3 text-sm font-semibold text-white transition hover:bg-black">
            ابحث
        </button>
    </div>

    <div class="mt-5 grid gap-6 lg:grid-cols-[280px_1fr]">

        {{-- ═══ الفلاتر ═══ --}}
        <aside>
            <button type="button" @click="$refs.panel.classList.toggle('hidden')"
                    class="mb-3 w-full rounded-xl border border-line bg-white px-4 py-3 text-start text-sm font-semibold lg:hidden">
                تصفية النتائج
            </button>

            <div x-ref="panel" class="hidden space-y-5 lg:block">
                @include('search::partials.filters')
            </div>
        </aside>

        {{-- ═══ النتائج ═══ --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3">
                <p class="text-sm text-muted">
                    <span class="font-semibold text-ink">{{ number_format($listings->total()) }}</span> إعلان
                    @if ($criteria->hasPriceFilter())
                        <span class="text-ink">·</span>
                        <span>ضمن نطاقك السعري بعد توحيد العملات</span>
                    @endif
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <span class="text-muted">الترتيب</span>
                    <select name="sort" onchange="this.form.submit()"
                            class="rounded-lg border border-line bg-white py-1.5 pe-8 ps-2 text-sm focus:border-brand focus:outline-none">
                        <option value="newest" @selected($criteria->sort === 'newest')>الأحدث</option>
                        <option value="oldest" @selected($criteria->sort === 'oldest')>الأقدم</option>
                        <option value="price_asc" @selected($criteria->sort === 'price_asc')>الأقل قيمة</option>
                        <option value="price_desc" @selected($criteria->sort === 'price_desc')>الأعلى قيمة</option>
                    </select>
                </label>
            </div>

            {{--
                التراجع يُعلَن، ولا يُخفى.

                عرض نتائج «أقرب» كما لو كانت مطابقة تامة يُربك المستخدم:
                يرى إعلانات لا تحمل كل كلماته فيحسب المحرك مكسوراً. الجملة
                الواحدة هنا تحوّل الشعور من «البحث لا يفهمني» إلى «لا يوجد
                ما طلبته بالضبط، وهذه البدائل».
            --}}
            @if ($matchMode === 'near')
                <p role="status" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    لا يوجد إعلان يطابق كل كلماتك. هذه أقرب النتائج، مرتّبة بعدد الكلمات المطابقة.
                </p>
            @endif

            {{-- توزيع العملات: يُظهر للمستخدم أن النتائج مختلطة العملات بدل أن يفاجأ --}}
            @if (count($breakdown) > 1)
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-tint p-3 text-xs">
                    <span class="font-semibold text-ink">النتائج معروضة بعملات مختلفة:</span>
                    @foreach ($breakdown as $code => $total)
                        @php $cur = $currencies->firstWhere('code', $code); @endphp
                        <span class="rounded-md bg-white px-2 py-1 font-medium text-muted ring-1 ring-line">
                            {{ $cur?->name_ar ?? $code }} — {{ number_format($total) }}
                        </span>
                    @endforeach
                    <span class="w-full text-muted">القيم موحّدة داخلياً بـ{{ $baseCurrency->name_ar }} للمقارنة والترتيب.</span>
                </div>
            @endif

            @if ($listings->isEmpty())
                <div class="mt-10 rounded-2xl border border-dashed border-line bg-white px-6 py-14 text-center">
                    <p class="text-base font-semibold">لا توجد إعلانات مطابقة</p>

                    @if ($matchMode === 'none')
                        {{-- المستخدم يستحق أن يعرف أننا لم نكتفِ بمحاولة واحدة --}}
                        <p class="mt-2 text-sm text-muted">
                            جرّبنا كلماتك مجتمعةً، ثم كل كلمة على حدة ومعها مكافئاتها — بلا نتيجة.
                        </p>
                    @endif

                    <p class="mt-2 text-sm text-muted">
                        وسّع النطاق السعري، أو أزل بعض الفلاتر، أو جرّب كلمة أعم.
                    </p>
                    <a href="{{ route('search') }}"
                       class="mt-5 inline-block rounded-lg border border-line px-4 py-2 text-sm font-medium transition hover:bg-tint">
                        إزالة كل الفلاتر
                    </a>
                </div>
            @else
                <ul class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($listings as $i => $listing)
                        @include('search::partials.listing-card', [
                            'listing' => $listing,
                            'baseCurrency' => $baseCurrency,
                            'position' => ($listings->firstItem() ?? 1) + $i,
                            'queryNormalized' => $criteria->query,
                        ])
                    @endforeach
                </ul>

                <div class="mt-8">
                    {{ $listings->links() }}
                </div>
            @endif
        </section>
    </div>
</form>

@endsection
BLADE;

// ─── اختبارات البحث ───

$files['tests/Feature/Search/SearchInputGuardsTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Search;

use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * حراسة المدخلات على مستوى النوع.
 *
 * القائمة البيضاء القائمة تحمي *القيم*؛ هذه الاختبارات تحمي *الأنواع*.
 * الفرق ليس نظرياً: ?q[]=x كان يُسقط الصفحة الرئيسية بخطأ 500 لأن
 * Stringable يحوّل المصفوفة بـ(string)، وPHP يُطلق تحذيراً، ولارافل
 * يحوّل التحذير إلى استثناء. رابط من سطر واحد يُسقط أهم صفحة في الموقع.
 */
class SearchInputGuardsTest extends TestCase
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
    public function an_array_query_does_not_bring_down_the_page(): void
    {
        $this->get('/?q[]=%D8%B4%D9%82%D8%A9')->assertOk();
    }

    #[Test]
    public function an_array_in_any_filter_is_ignored_rather_than_fatal(): void
    {
        $this->get('/?sort[]=newest&currency[]=YER&condition[]=new&min[]=5&category[]=1')
            ->assertOk();
    }

    #[Test]
    public function an_array_query_is_read_as_no_query(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['q' => ['شقة']]));

        $this->assertFalse($criteria->hasQuery());
    }

    #[Test]
    public function a_very_long_query_is_truncated_before_it_reaches_the_database(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge([
            'q' => str_repeat('سيارة ', 500),
        ]));

        $this->assertLessThanOrEqual(
            SearchCriteria::MAX_QUERY_LENGTH,
            mb_strlen((string) $criteria->query)
        );
    }

    /** «من abc» كان يصبح «من صفر» — حدّ لم يطلبه أحد */
    #[Test]
    public function a_non_numeric_price_bound_is_dropped_not_read_as_zero(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['min' => 'abc']));

        $this->assertNull($criteria->priceMin);
        $this->assertFalse($criteria->hasPriceFilter());
    }

    #[Test]
    public function a_negative_bound_is_still_ignored(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['min' => '-50', 'max' => '100']));

        $this->assertNull($criteria->priceMin);
        $this->assertSame(100.0, $criteria->priceMax);
    }

    #[Test]
    public function per_page_stays_inside_its_bounds(): void
    {
        $this->assertSame(60, SearchCriteria::fromRequest(request()->merge(['per_page' => '5000']))->perPage);
        $this->assertSame(6, SearchCriteria::fromRequest(request()->merge(['per_page' => '1']))->perPage);
        $this->assertSame(24, SearchCriteria::fromRequest(request()->merge(['per_page' => 'abc']))->perPage);
    }
}
PHP;

$files['tests/Feature/Search/SearchFallbackTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الدرجتان: التامة ثم الأقرب.
 *
 * أهم اختبار هنا هو الثاني: الاشتراط الصارم كان يُرجع صفراً لاستعلام
 * واقعي تماماً («كورولا 2018 اوتوماتيك») لأن كلمة واحدة لم يكتبها
 * المعلن — والمستخدم يقرأ الصفر كغياب السلعة من المنصة، لا كقصور في
 * المطابقة. هذا أغلى عيب يمكن أن يحمله محرك بحث.
 */
class SearchFallbackTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private User $user;

    private Category $category;

    private Location $location;

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

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->search = app(SearchQueryBuilder::class);
    }

    private function listing(string $title, ?string $description = null): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'description' => $description,
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_full_match_reports_the_exact_mode(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018'));

        $this->assertSame('exact', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    /** كلمة واحدة زائدة لم يكتبها المعلن لا يجوز أن تُفرغ الصفحة */
    #[Test]
    public function one_missing_word_falls_back_to_the_nearest_results(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018 اوتوماتيك'));

        $this->assertSame('near', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    #[Test]
    public function the_nearest_results_are_ordered_by_how_many_words_matched(): void
    {
        $two = $this->listing('تويوتا كورولا موديل 2018');
        $one = $this->listing('نيسان صني موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018 اوتوماتيك'));

        $ids = $result['listings']->pluck('id')->all();

        $this->assertSame('near', $result['mode']);
        $this->assertSame([$two->id, $one->id], $ids);
    }

    /**
     * التراجع لا يعمل إلا عند الحاجة.
     *
     * لو عمل دائماً لظهرت كل إعلانات «موديل» فوق النتائج الصحيحة —
     * وتغطية زائدة لا يريدها المستخدم أسوأ من نتائج أقل.
     */
    #[Test]
    public function the_fallback_does_not_run_when_the_strict_pass_succeeds(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');
        $this->listing('نيسان صني موديل 2005');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018'));

        $this->assertSame('exact', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    #[Test]
    public function nothing_at_all_reports_the_none_mode(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'غواصة نووية'));

        $this->assertSame('none', $result['mode']);
        $this->assertSame(0, $result['listings']->total());
    }

    #[Test]
    public function browsing_without_a_query_is_not_a_fallback(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $this->assertSame('browse', $this->search->search(new SearchCriteria)['mode']);
    }

    /** الفلاتر تبقى ملزمة في الدرجة الثانية — التراجع في النص لا في الشروط */
    #[Test]
    public function the_fallback_still_respects_the_price_filter(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(
            query: 'كورولا 2018 اوتوماتيك',
            priceMin: 900000,
        ));

        $this->assertSame(0, $result['listings']->total());
    }

    #[Test]
    public function the_page_tells_the_user_when_it_fell_back(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $this->get('/?q=' . urlencode('كورولا 2018 اوتوماتيك'))
            ->assertOk()
            ->assertSee('أقرب النتائج');
    }
}
PHP;

$files['tests/Feature/Search/SearchSynonymTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\Database\Seeders\SynonymSeeder;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Search\Services\SynonymExpander;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * المكافئات — «المستوى الثاني» من الترتيب المطلوب.
 *
 * جدول search_synonyms كان في المخطط من أول هجرة ولا سطر يقرأه. أثره
 * الحقيقي في اليمن: من يكتب «موتر» أو «دباب» بلهجته كان يُعاقَب بصفر
 * نتائج، ومن يكتب بالفصحى يجد. محرك بحث يفضّل لهجةً على أخرى في سوق
 * محلي عيب في المنتج لا في البيانات.
 */
class SearchSynonymTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private User $user;

    private Category $category;

    private Location $location;

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

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        (new SynonymSeeder)->run();

        $this->search = app(SearchQueryBuilder::class);
    }

    private function listing(string $title): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_dialect_word_finds_the_formal_one(): void
    {
        $this->listing('سيارة تويوتا كورولا للبيع');

        $results = $this->search->build(new SearchCriteria(query: 'موتر تويوتا'))->get();

        $this->assertCount(1, $results);
        $this->assertSame('exact', $this->search->search(new SearchCriteria(query: 'موتر تويوتا'))['mode']);
    }

    /** العلاقة متبادلة: صف واحد يخدم الاتجاهين */
    #[Test]
    public function the_formal_word_finds_the_dialect_one(): void
    {
        $this->listing('عربية هيونداي النترا');

        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'سيارة النترا'))->get());
    }

    #[Test]
    public function the_phone_family_is_interchangeable(): void
    {
        $this->listing('موبايل سامسونج جالكسي');

        foreach (['جوال سامسونج', 'تلفون سامسونج', 'هاتف سامسونج'] as $query) {
            $this->assertCount(1, $this->search->build(new SearchCriteria(query: $query))->get(), $query);
        }
    }

    /**
     * الحدّ الذي لا يُعبَر.
     *
     * «للبيع» و«للإيجار» ليسا مكافئين، وإدخالهما زوجاً يجعل من يبحث عن
     * شقة للإيجار يرى شققاً للبيع — وهذا في العقار ليس ضجيجاً بل خطأ
     * يهدر وقت الطرفين. الاختبار يحرس القاعدة لا التنفيذ.
     */
    #[Test]
    public function opposites_are_never_treated_as_equivalents(): void
    {
        $this->assertSame(0, DB::table('search_synonyms')
            ->whereIn('term', ['للبيع', 'بيع'])
            ->whereIn('synonym', ['للايجار', 'ايجار'])
            ->count());

        $this->listing('شقة للإيجار في صنعاء');

        $this->assertCount(0, $this->search->build(new SearchCriteria(query: 'شقة للبيع'))->get());
    }

    #[Test]
    public function the_stored_terms_are_normalized(): void
    {
        foreach (DB::table('search_synonyms')->pluck('term') as $term) {
            $this->assertSame(
                $term,
                app(\App\Modules\Shared\Services\ArabicNormalizer::class)->normalize($term)
            );
        }
    }

    /** سقف المكافئات لكل كلمة — وإلا صار الاستعلام أربعين شرط LIKE */
    #[Test]
    public function the_number_of_variants_per_word_is_capped(): void
    {
        $groups = app(SynonymExpander::class)->expand(['جوال']);

        $this->assertLessThanOrEqual(SynonymExpander::MAX_PER_TOKEN + 1, count($groups[0]));
    }

    #[Test]
    public function a_word_without_synonyms_expands_to_itself_only(): void
    {
        $this->assertSame([['هيونداي']], app(SynonymExpander::class)->expand(['هيونداي']));
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $before = DB::table('search_synonyms')->count();

        (new SynonymSeeder)->run();

        $this->assertSame($before, DB::table('search_synonyms')->count());
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// الكتابة
// ═════════════════════════════════════════════════════════════════════════════

$written = 0;

foreach ($files as $relativePath => $contents) {
    $path = __DIR__ . '/' . $relativePath;
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "فشل إنشاء المجلد: {$directory}\n");
        exit(1);
    }

    if (is_file($path)) {
        copy($path, $path . '.bak');
    }

    if (file_put_contents($path, str_replace("\r\n", "\n", $contents)) === false) {
        fwrite(STDERR, "فشل الكتابة: {$relativePath}\n");
        exit(1);
    }

    echo "✓ {$relativePath}\n";
    $written++;
}

echo "\nكُتب {$written} ملفاً.\n\n";
echo "الخطوات:\n";
echo "  composer dump-autoload\n";
echo "  php artisan optimize:clear\n";
echo "  php artisan db:seed --class=\"App\\\\Modules\\\\Search\\\\Database\\\\Seeders\\\\SynonymSeeder\"\n";
echo "  php artisan test\n";
echo "  php artisan souq:check\n\n";
echo "الْتزامات مقترحة:\n";
echo "  fix(identity): add the missing isActive check the login flow calls\n";
echo "  fix(catalog): sign in the publisher in the image upload test\n";
echo "  fix(catalog): make the renewal and preview tests independent of timing and guard state\n";
echo "  fix(search): ignore non-scalar and non-numeric query parameters\n";
echo "  fix(search): cap the query and phrase length before they reach a LIKE pattern\n";
echo "  feat(search): expand query words with dialect equivalents from search_synonyms\n";
echo "  feat(search): fall back to nearest matches ranked by matched word count\n";
echo "  perf(search): skip the currency breakdown query when there are no results\n";
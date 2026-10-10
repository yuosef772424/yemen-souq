<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Analytics\Jobs\PersistEvents;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * اختبارات توصيفية لوجهَي لوحة المعلن: واجهة الـAPI (`/api/v1/me/listings`)
 * وصفحات الويب (`/account/listings`).
 *
 * كُتبت قبل استخراج المنطق المشترك من المتحكّمين إلى طبقة الخدمات، وتثبّت
 * ما يلحظه العميل: مفاتيح JSON وحالات HTTP والرسائل ووجهات إعادة التوجيه
 * ورسائل الجلسة. أيّ استخراج يجب أن يمرّ بها بلا تعديل.
 */
class ListingPanelContractTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('filesystems.media', 'public'));

        $this->seedReferenceData();

        $this->owner = User::factory()->create([
            'phone' => '+9677'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
        ]);
        $this->stranger = User::factory()->create();
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'تويوتا كورولا موديل 2018 وكالة',
            'description' => 'الحالة ممتازة والاستخدام بسيط.',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'price_type' => 'fixed',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'contact_phone' => '+967771234567',
            ...$overrides,
        ];
    }

    private function image(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 900, 700);
    }

    /** مجموعة إعلانات تغطي كل التبويبات: 2 قائم، 1 مباع، 1 منتهٍ، 1 محذوف */
    private function seedEveryTab(): void
    {
        $this->listingFor($this->owner, ['title' => 'إعلان قائم أول']);
        $this->listingFor($this->owner, ['title' => 'إعلان قائم ثان']);
        $this->listingFor($this->owner, ['title' => 'إعلان مباع', 'status' => 'sold', 'sold_at' => now()]);
        $this->listingFor($this->owner, ['title' => 'إعلان منتهٍ', 'expires_at' => now()->subDay()]);
        $this->listingFor($this->owner, ['title' => 'إعلان محذوف'])->delete();
        $this->listingFor($this->stranger, ['title' => 'إعلان غريب']);
    }

    // ═══ API: القراءة ═══

    #[Test]
    public function api_index_reports_tab_and_counts_for_every_state(): void
    {
        $this->seedEveryTab();

        $response = $this->api()->getJson('/api/v1/me/listings')->assertOk();

        $response->assertJsonPath('meta.tab', 'live')
            ->assertJsonPath('meta.counts', ['live' => 2, 'sold' => 1, 'expired' => 1, 'trashed' => 1, 'all' => 4])
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function api_index_filters_by_tab_and_falls_back_to_live(): void
    {
        $this->seedEveryTab();

        $titles = fn (string $query) => collect(
            $this->api()->getJson('/api/v1/me/listings'.$query)->assertOk()->json('data')
        )->pluck('title')->sort()->values()->all();

        $this->assertSame(['إعلان مباع'], $titles('?tab=sold'));
        $this->assertSame(['إعلان منتهٍ'], $titles('?tab=expired'));
        $this->assertSame(['إعلان محذوف'], $titles('?tab=trashed'));
        $this->assertCount(4, $titles('?tab=all'));

        $this->api()->getJson('/api/v1/me/listings?tab=nonsense')
            ->assertOk()
            ->assertJsonPath('meta.tab', 'live')
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function api_index_honours_per_page(): void
    {
        $this->seedEveryTab();

        $this->api()->getJson('/api/v1/me/listings?tab=all&per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 4);
    }

    #[Test]
    public function api_show_returns_detail_raw_attributes_and_renewability(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->api()->getJson("/api/v1/me/listings/{$listing->slug}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'slug', 'title'], 'raw_attributes', 'can_renew'])
            ->assertJsonPath('data.slug', $listing->slug)
            ->assertJsonPath('can_renew', false);
    }

    #[Test]
    public function api_show_lets_the_owner_open_a_trashed_listing_but_not_a_stranger(): void
    {
        $listing = $this->listingFor($this->owner);
        $listing->delete();

        $this->api()->getJson("/api/v1/me/listings/{$listing->slug}")->assertOk();
        $this->api($this->stranger)->getJson("/api/v1/me/listings/{$listing->slug}")->assertForbidden();
    }

    #[Test]
    public function api_stats_clamps_the_window_between_7_and_90_days(): void
    {
        $listing = $this->listingFor($this->owner);

        $rows = fn (string $query) => count(
            $this->api()->getJson("/api/v1/me/listings/{$listing->slug}/stats{$query}")
                ->assertOk()->assertJsonStructure(['data' => ['rows', 'totals', 'peak']])->json('data.rows')
        );

        $this->assertSame(7, $rows('?days=1'));
        $this->assertSame(30, $rows(''));
        $this->assertSame(90, $rows('?days=500'));
    }

    // ═══ API: النشر ═══

    #[Test]
    public function api_store_returns_the_published_envelope_and_records_the_event(): void
    {
        Bus::fake();

        $response = $this->api()->postJson('/api/v1/me/listings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'نُشر الإعلان.')
            ->assertJsonPath('images_stored', 0)
            ->assertJsonPath('warnings', [])
            ->assertJsonStructure(['message', 'data' => ['id', 'slug', 'title'], 'images_stored', 'warnings']);

        $listing = Listing::firstOrFail();

        $this->assertSame($this->owner->id, $listing->user_id);
        $this->assertSame('active', $listing->status);
        $this->assertNotNull($listing->published_at);
        $this->assertEqualsWithDelta(30, now()->diffInDays($listing->expires_at, false), 1);
        $response->assertJsonPath('data.slug', $listing->slug);

        Bus::assertDispatched(PersistEvents::class, function (PersistEvents $job) use ($listing): bool {
            $rows = (new \ReflectionProperty($job, 'rows'))->getValue($job);

            return collect($rows)->contains(fn (array $row): bool => $row['event_name'] === 'listing_created'
                && (int) $row['entity_id'] === $listing->id
                && json_decode($row['payload'], true)['images'] === 0);
        });
    }

    #[Test]
    public function api_store_attaches_images_in_the_same_request(): void
    {
        $this->api()->post('/api/v1/me/listings', $this->payload([
            'images' => [$this->image('a.jpg'), $this->image('b.jpg')],
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('images_stored', 2);

        $this->assertSame(2, Listing::firstOrFail()->images()->count());
    }

    #[Test]
    public function api_store_creates_nothing_when_validation_fails(): void
    {
        $this->api()->postJson('/api/v1/me/listings', $this->payload(['title' => 'x']))
            ->assertStatus(422);

        $this->assertSame(0, Listing::count());
    }

    // ═══ API: التعديل والصور ═══

    #[Test]
    public function api_update_returns_the_envelope_and_keeps_the_slug(): void
    {
        $listing = $this->listingFor($this->owner);
        $slug = $listing->slug;

        $this->api()->post("/api/v1/me/listings/{$slug}", $this->payload([
            'title' => 'تويوتا كورولا موديل 2019 وكالة',
            'images' => [$this->image()],
        ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'حُدِّث الإعلان.')
            ->assertJsonPath('images_stored', 1)
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('data.title', 'تويوتا كورولا موديل 2019 وكالة');

        $fresh = $listing->fresh();

        $this->assertSame($slug, $fresh->slug);
        $this->assertSame(1, $fresh->images()->count());
    }

    #[Test]
    public function api_update_reports_images_beyond_the_cap_as_warnings(): void
    {
        $listing = $this->listingFor($this->owner);

        for ($i = 0; $i < 8; $i++) {
            ListingImage::create([
                'listing_id' => $listing->id, 'path' => "listings/{$listing->id}/{$i}.jpg",
                'thumb_path' => "listings/{$listing->id}/t{$i}.jpg", 'width' => 10, 'height' => 10,
                'size_bytes' => 1, 'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(),
            ]);
        }

        $this->api()->post("/api/v1/me/listings/{$listing->slug}", $this->payload([
            'images' => [$this->image()],
        ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('images_stored', 0)
            ->assertJsonPath('warnings.0', 'تم قبول 8 صور كحد أقصى للإعلان.');
    }

    #[Test]
    public function api_destroy_image_removes_it_and_is_scoped_to_the_listing(): void
    {
        $listing = $this->listingFor($this->owner);
        $other = $this->listingFor($this->owner, ['title' => 'إعلان آخر للمالك نفسه']);

        $image = ListingImage::create([
            'listing_id' => $listing->id, 'path' => 'listings/x.jpg', 'thumb_path' => 'listings/xt.jpg',
            'width' => 10, 'height' => 10, 'size_bytes' => 1, 'is_primary' => true, 'sort_order' => 0,
            'created_at' => now(),
        ]);

        // معرّف صورة من إعلان آخر في المسار: scopeBindings يرفضه
        $this->api()->deleteJson("/api/v1/me/listings/{$other->slug}/images/{$image->id}")->assertNotFound();
        $this->api($this->stranger)->deleteJson("/api/v1/me/listings/{$listing->slug}/images/{$image->id}")->assertForbidden();

        $this->api()->deleteJson("/api/v1/me/listings/{$listing->slug}/images/{$image->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'حُذفت الصورة.']);

        $this->assertDatabaseMissing('listing_images', ['id' => $image->id]);
    }

    // ═══ API: الحذف والاستعادة والتجديد والبيع ═══

    #[Test]
    public function api_destroy_and_restore_messages_and_payload(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->api()->deleteJson("/api/v1/me/listings/{$listing->slug}")
            ->assertOk()
            ->assertExactJson(['message' => 'حُذف الإعلان. يمكنك استعادته من تبويب «المحذوفة».']);

        $this->api()->postJson("/api/v1/me/listings/{$listing->slug}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'استُعيد الإعلان.')
            ->assertJsonPath('data.slug', $listing->slug)
            ->assertJsonStructure(['message', 'data' => ['id', 'slug']]);

        $this->api($this->stranger)->postJson("/api/v1/me/listings/{$listing->slug}/restore")->assertForbidden();
    }

    #[Test]
    public function api_renew_carries_message_code_and_data_with_matching_status(): void
    {
        $due = $this->listingFor($this->owner, ['expires_at' => now()->addDays(2)]);
        $early = $this->listingFor($this->owner, ['title' => 'إعلان مبكر التجديد', 'expires_at' => now()->addDays(20)]);
        $sold = $this->listingFor($this->owner, ['title' => 'إعلان مباع للتجديد', 'status' => 'sold', 'sold_at' => now()]);

        $this->api()->postJson("/api/v1/me/listings/{$due->slug}/renew")
            ->assertOk()
            ->assertJsonPath('code', 'renewed')
            ->assertJsonPath('message', 'تم تجديد الإعلان.')
            ->assertJsonPath('data.slug', $due->slug);

        $this->api()->postJson("/api/v1/me/listings/{$early->slug}/renew")
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_early')
            ->assertJsonStructure(['message', 'code', 'data' => ['id']]);

        $this->api()->postJson("/api/v1/me/listings/{$sold->slug}/renew")
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_renewable');
    }

    #[Test]
    public function api_toggle_sold_flips_both_ways_and_rejects_with_a_code(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->api()->postJson("/api/v1/me/listings/{$listing->slug}/sold")
            ->assertOk()
            ->assertJsonPath('message', 'عُلّم الإعلان مباعاً واختفى من نتائج البحث.')
            ->assertJsonPath('data.slug', $listing->slug);

        $this->assertSame('sold', $listing->fresh()->status);

        $this->api()->postJson("/api/v1/me/listings/{$listing->slug}/sold")
            ->assertOk()
            ->assertJsonPath('message', 'أُعيد الإعلان إلى العرض.');

        $this->assertSame('active', $listing->fresh()->status);

        // حالة لا يصحّ البيع منها: الرفض برمز ثابت وبـ422
        $listing->forceFill(['status' => 'pending'])->save();

        $this->api()->postJson("/api/v1/me/listings/{$listing->slug}/sold")
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'لا يمكن تعليم هذا الإعلان مباعاً في حالته الحالية.',
                'code' => 'sale_toggle_rejected',
            ]);

        $this->assertSame('pending', $listing->fresh()->status);
    }

    // ═══ الويب: القائمة ═══

    #[Test]
    public function web_index_passes_tab_counts_and_twelve_per_page(): void
    {
        $this->seedEveryTab();

        $response = $this->actingAs($this->owner)->get('/account/listings')->assertOk();

        $response->assertViewHas('tab', 'live');
        $response->assertViewHas('counts', ['live' => 2, 'sold' => 1, 'expired' => 1, 'trashed' => 1, 'all' => 4]);
        $this->assertSame(12, $response->viewData('listings')->perPage());
        $this->assertSame(2, $response->viewData('listings')->total());

        $this->actingAs($this->owner)->get('/account/listings?tab=sold')
            ->assertViewHas('tab', 'sold');
        $this->actingAs($this->owner)->get('/account/listings?tab=zzz')
            ->assertViewHas('tab', 'live');
    }

    // ═══ الويب: الإجراءات ═══

    #[Test]
    public function web_update_flashes_status_and_warnings_and_redirects_to_edit(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $listing), $this->payload())
            ->assertRedirect(route('account.listings.edit', $listing))
            ->assertSessionHas('status', 'حُدِّث الإعلان.')
            ->assertSessionHas('warnings', []);

        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $listing), $this->payload(['images' => [$this->image()]]))
            ->assertRedirect(route('account.listings.edit', $listing))
            ->assertSessionHas('status', 'حُدِّث الإعلان وأُضيفت 1 صورة.');
    }

    #[Test]
    public function web_destroy_image_redirects_to_edit_with_status(): void
    {
        $listing = $this->listingFor($this->owner);

        $image = ListingImage::create([
            'listing_id' => $listing->id, 'path' => 'listings/y.jpg', 'thumb_path' => 'listings/yt.jpg',
            'width' => 10, 'height' => 10, 'size_bytes' => 1, 'is_primary' => true, 'sort_order' => 0,
            'created_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->delete(route('account.listings.images.destroy', [$listing, $image]))
            ->assertRedirect(route('account.listings.edit', $listing))
            ->assertSessionHas('status', 'حُذفت الصورة.');

        $this->assertDatabaseMissing('listing_images', ['id' => $image->id]);
    }

    #[Test]
    public function web_destroy_and_restore_flash_their_messages(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->actingAs($this->owner)
            ->delete(route('account.listings.destroy', $listing))
            ->assertRedirect(route('account.listings', ['tab' => 'trashed']))
            ->assertSessionHas('status', 'حُذف الإعلان. يمكنك استعادته من تبويب «المحذوفة».');

        $this->actingAs($this->owner)
            ->patch(route('account.listings.restore', $listing))
            ->assertRedirect(route('account.listings'))
            ->assertSessionHas('status', 'استُعيد الإعلان.');
    }

    #[Test]
    public function web_renew_redirects_by_outcome(): void
    {
        $due = $this->listingFor($this->owner, ['expires_at' => now()->addDays(2)]);
        $early = $this->listingFor($this->owner, ['title' => 'إعلان مبكر التجديد', 'expires_at' => now()->addDays(20)]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $due))
            ->assertRedirect(route('account.listings', ['tab' => 'live']))
            ->assertSessionHas('status', 'تم تجديد الإعلان.');

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $early))
            ->assertRedirect(route('account.listings', ['tab' => 'expired']))
            ->assertSessionHas('error');
    }

    #[Test]
    public function web_toggle_sold_redirects_and_flashes_per_branch(): void
    {
        $listing = $this->listingFor($this->owner);

        $this->actingAs($this->owner)
            ->post(route('account.listings.sold', $listing))
            ->assertRedirect(route('account.listings', ['tab' => 'sold']))
            ->assertSessionHas('status', 'عُلّم الإعلان مباعاً واختفى من نتائج البحث. يمكنك التراجع من هنا.');

        $this->actingAs($this->owner)
            ->post(route('account.listings.sold', $listing))
            ->assertRedirect(route('account.listings'))
            ->assertSessionHas('status', 'أُعيد الإعلان إلى العرض.');

        $listing->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->owner)
            ->post(route('account.listings.sold', $listing))
            ->assertRedirect(route('account.listings'))
            ->assertSessionHas('error', 'لا يمكن تعليم هذا الإعلان مباعاً في حالته الحالية.');
    }

    #[Test]
    public function web_rejects_a_stranger_on_every_listing_action(): void
    {
        $listing = $this->listingFor($this->owner);
        $listing->images()->create([
            'path' => 'listings/z.jpg', 'thumb_path' => 'listings/zt.jpg', 'width' => 10, 'height' => 10,
            'size_bytes' => 1, 'is_primary' => true, 'sort_order' => 0, 'created_at' => now(),
        ]);
        $image = $listing->images()->first();

        $as = fn () => $this->actingAs($this->stranger);

        $as()->get(route('account.listings.edit', $listing))->assertForbidden();
        $as()->put(route('account.listings.update', $listing), $this->payload())->assertForbidden();
        $as()->delete(route('account.listings.images.destroy', [$listing, $image]))->assertForbidden();
        $as()->post(route('account.listings.renew', $listing))->assertForbidden();
        $as()->post(route('account.listings.sold', $listing))->assertForbidden();
        $as()->get(route('account.listings.stats', $listing))->assertForbidden();
        $as()->delete(route('account.listings.destroy', $listing))->assertForbidden();

        $listing->delete();
        $as()->patch(route('account.listings.restore', $listing))->assertForbidden();
    }
}

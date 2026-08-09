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
     * الأرقام تُفحص على القارئ لا على HTML الصفحة.
     *
     * assertSee('20') على صفحة تحمل تواريخ مثل 2026-08-10 ينجح بلا أي
     * علاقة بالمجموع — اختبار يمر دائماً أسوأ من اختبار غائب.
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

        $stats = app(ListingStatsReader::class)->forListing($this->listing);

        $this->assertSame(0, $stats['totals']['views']);
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

    /**
     * الفراغ يجب أن يقول سببه.
     *
     * جدول أصفار بلا تفسير يقول للمعلن «لم يرَك أحد»، والصحيح «لم يُحسب
     * بعد». المعنيان مختلفان تماماً، والثاني يُصلَح بأمر واحد.
     */
    #[Test]
    public function an_empty_rollup_says_so_instead_of_showing_bare_zeros(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account.listings.stats', $this->listing))
            ->assertOk()
            ->assertSee('analytics:rollup');
    }

    /**
     * زيارة المالك لإعلانه لا تُحسب.
     *
     * بلا هذا الشرط يكون الرقم الذي جاء المعلن ليقرأه من صنعه هو —
     * وأسوأ من رقم مفقود رقمٌ يبدو صحيحاً وليس كذلك.
     */
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

    /** المالك يعاين إعلاناً موقوفاً؛ الزائر لا يجده */
    #[Test]
    public function the_owner_may_preview_a_paused_listing_but_a_visitor_may_not(): void
    {
        $this->listing->forceFill(['status' => 'paused'])->save();

        $this->actingAs($this->owner)->get(route('listing.show', $this->listing))->assertOk();
        $this->get(route('listing.show', $this->listing))->assertNotFound();
    }
}
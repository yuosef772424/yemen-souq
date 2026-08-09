<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * التجديد.
 *
 * الاختبار الثاني هو حجر الزاوية: تجديد يحرّك published_at يحوّل زر
 * التجديد إلى ترقية مجانية، فيفقد ترتيب «الأحدث» معناه ويبدو إعلان
 * عمره سنة جديداً. لو حُذف هذا الاختبار لسقط القرار بلا أن يلاحظه أحد.
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

        $listing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->assertSame(2, $listing->fresh()->renewals_count);
    }
}
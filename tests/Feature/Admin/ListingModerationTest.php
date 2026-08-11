<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

class ListingModerationTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    public function test_approving_stamps_the_review_and_logs_it(): void
    {
        $admin = $this->moderator();
        $listing = $this->listing(['reviewed_at' => null]);

        $this->actingAs($admin)
            ->post(route('admin.listings.approve', $listing))
            ->assertRedirect();

        $listing->refresh();

        $this->assertNotNull($listing->reviewed_at);
        $this->assertSame($admin->id, (int) $listing->reviewed_by);

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::ListingApproved->value,
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
        ]);
    }

    /** الاعتماد يمحو رفضاً سابقاً — وإلا بقي نصّ الرفض معروضاً لإعلان مقبول */
    public function test_approving_a_rejected_listing_republishes_it(): void
    {
        $listing = $this->listing(['status' => 'rejected']);
        $listing->forceFill(['reject_reason' => 'سبب قديم'])->save();

        $this->actingAs($this->moderator())
            ->post(route('admin.listings.approve', $listing))
            ->assertRedirect();

        $listing->refresh();

        $this->assertSame('active', $listing->status);
        $this->assertNull($listing->reject_reason);
    }

    public function test_rejection_without_a_real_reason_is_refused(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->moderator())
            ->from(route('admin.listings'))
            ->post(route('admin.listings.reject', $listing), ['reason' => 'سيء'])
            ->assertSessionHasErrors('reason');

        $this->assertNotSame('rejected', $listing->fresh()->status);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_rejection_stores_the_reason_where_the_owner_sees_it(): void
    {
        $listing = $this->listing();
        $reason = 'الصور لا تطابق وصف السلعة، أعد رفعها.';

        $this->actingAs($this->moderator())
            ->post(route('admin.listings.reject', $listing), ['reason' => $reason])
            ->assertRedirect();

        $listing->refresh();

        $this->assertSame('rejected', $listing->status);
        $this->assertSame($reason, $listing->reject_reason);

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::ListingRejected->value,
            'reason' => $reason,
        ]);
    }

    /** التمييز بمدة: is_featured بلا featured_until يعني تثبيتاً أبدياً */
    public function test_featuring_always_sets_an_expiry(): void
    {
        $listing = $this->listing(['is_featured' => false]);

        $this->actingAs($this->moderator())
            ->post(route('admin.listings.feature', $listing), ['days' => 3])
            ->assertRedirect();

        $listing->refresh();

        $this->assertTrue((bool) $listing->is_featured);
        $this->assertNotNull($listing->featured_until);
        $this->assertTrue($listing->featured_until->isFuture());
        $this->assertSame(3, (int) round(now()->diffInDays($listing->featured_until, false)));
    }

    public function test_deleting_is_reversible_and_both_steps_are_logged(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->moderator())
            ->delete(route('admin.listings.destroy', $listing))
            ->assertRedirect();

        $this->assertSoftDeleted('listings', ['id' => $listing->id]);

        $this->actingAs($this->moderator())
            ->post(route('admin.listings.restore', $listing))
            ->assertRedirect();

        $this->assertNotSoftDeleted('listings', ['id' => $listing->id]);

        $this->assertDatabaseHas('admin_actions', ['action' => AdminAction::ListingDeleted->value]);
        $this->assertDatabaseHas('admin_actions', ['action' => AdminAction::ListingRestored->value]);
    }

    /** الطابور = ما لم يُراجَع، لا ما حالته pending (ولا شيء يكتب تلك الحالة) */
    public function test_queue_tab_shows_only_unreviewed_listings(): void
    {
        $waiting = $this->listing(['reviewed_at' => null, 'title' => 'إعلان ينتظر المراجعة']);

        $reviewed = $this->listing(['title' => 'إعلان روجع سابقاً']);
        $reviewed->forceFill(['reviewed_at' => now()])->save();

        $this->actingAs($this->moderator())
            ->get(route('admin.listings', ['tab' => 'queue']))
            ->assertOk()
            ->assertSee($waiting->title)
            ->assertDontSee($reviewed->title);
    }
}

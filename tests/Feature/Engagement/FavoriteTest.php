<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\FavoriteToggler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->user = User::factory()->create();
        $this->listing = $this->listingFor(User::factory()->create());
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('listing.favorite', $this->listing))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_first_press_adds_and_the_second_removes(): void
    {
        $this->actingAs($this->user)
            ->from(route('listing.show', $this->listing))
            ->post(route('listing.favorite', $this->listing))
            ->assertRedirect(route('listing.show', $this->listing));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
        ]);
        $this->assertSame(1, (int) $this->listing->fresh()->favorites_count);

        $this->actingAs($this->user)->post(route('listing.favorite', $this->listing));

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
        ]);
        $this->assertSame(0, (int) $this->listing->fresh()->favorites_count);
    }

    /**
     * العدّاد لا يسبق الجدول.
     *
     * الخطأ الذي يحرسه هذا الاختبار: «أدرج ثم زد العدّاد». ضغطتان
     * متلاحقتان تعطيان إدراجاً واحداً — يمنعه المفتاح المركّب — وزيادتين
     * للعدّاد، فيصبح أكبر من عدد الصفوف بلا ما يكشفه لاحقاً.
     */
    #[Test]
    public function adding_twice_counts_once(): void
    {
        $toggler = app(FavoriteToggler::class);

        $this->assertTrue($toggler->toggle($this->user, $this->listing));

        // محاكاة الإدراج المكرّر بلا مرور بالتبديل
        \Illuminate\Support\Facades\DB::table('favorites')->insertOrIgnore([
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, (int) $this->listing->fresh()->favorites_count);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('favorites')->count());
    }

    #[Test]
    public function the_counter_never_goes_below_zero(): void
    {
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);

        $this->assertSame(0, (int) $this->listing->fresh()->favorites_count);
    }

    #[Test]
    public function two_users_can_favourite_the_same_listing(): void
    {
        $other = User::factory()->create();

        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($other, $this->listing);

        $this->assertSame(2, (int) $this->listing->fresh()->favorites_count);
    }

    #[Test]
    public function the_page_lists_only_the_users_own_favourites(): void
    {
        $mine = $this->listingFor(User::factory()->create(), ['title' => 'إعلان حفظته أنا']);
        $theirs = $this->listingFor(User::factory()->create(), ['title' => 'إعلان حفظه غيري']);

        app(FavoriteToggler::class)->toggle($this->user, $mine);
        app(FavoriteToggler::class)->toggle(User::factory()->create(), $theirs);

        $this->actingAs($this->user)
            ->get(route('account.favorites'))
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    /**
     * الإعلان المحذوف يبقى في المفضلة بوسم.
     *
     * إخفاؤه يجعل المستخدم يظن أنه فقد ما حفظه بلا تفسير، وإظهاره كأنه
     * قائم يهدر رحلة اتصال. الوسم هو الجواب.
     */
    #[Test]
    public function a_deleted_listing_stays_in_the_list_but_is_marked(): void
    {
        $listing = $this->listingFor(User::factory()->create(), ['title' => 'إعلان سيُحذف']);

        app(FavoriteToggler::class)->toggle($this->user, $listing);

        $listing->delete();

        $this->actingAs($this->user)
            ->get(route('account.favorites'))
            ->assertOk()
            ->assertSee($listing->title)
            ->assertSee('حُذف الإعلان');
    }
}
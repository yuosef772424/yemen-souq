<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListingImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

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

        $image = Listing::firstOrFail()->images()->firstOrFail();

        $this->assertLessThanOrEqual(1600, $image->width);
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
        $response = $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf')],
        ]));

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, Listing::count());
    }

    #[Test]
    public function files_over_the_size_limit_are_rejected(): void
    {
        $response = $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('big.jpg')->size(9000)],
        ]));

        $response->assertSessionHasErrors('images.0');
    }

    #[Test]
    public function tiny_images_are_rejected(): void
    {
        $response = $this->post(route('listing.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('icon.jpg', 50, 50)],
        ]));

        $response->assertSessionHasErrors('images.0');
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

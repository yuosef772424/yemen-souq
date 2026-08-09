<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * صور بديلة مولّدة محلياً كملفات SVG.
 *
 * لماذا لا نُنزّل صوراً من الإنترنت: الاعتماد على خدمة خارجية يجعل
 * البذرة تفشل بلا اتصال، وصور المنتجات الحقيقية محمية بحقوق.
 * SVG مولّد يعطي شبكة بطاقات تبدو مكتملة بلا أي من المشكلتين.
 */
class ListingImageSeeder extends Seeder
{
    private const PALETTE = [
        ['#16233f', '#2c3f6b'],
        ['#0e6e5e', '#149b83'],
        ['#6b4423', '#96693a'],
        ['#4a2d5e', '#6d4489'],
        ['#8c5a12', '#b8781c'],
        ['#1f4e5f', '#2d7189'],
    ];

    public function run(): void
    {
        Storage::disk('public')->makeDirectory('listings');

        $listings = Listing::query()->doesntHave('images')->get();

        foreach ($listings as $index => $listing) {
            $count = random_int(1, 3);

            for ($i = 0; $i < $count; $i++) {
                $path = "listings/{$listing->id}-{$i}.svg";

                Storage::disk('public')->put(
                    $path,
                    $this->svg($listing->title, ($index + $i) % count(self::PALETTE))
                );

                ListingImage::create([
                    'listing_id' => $listing->id,
                    'path' => $path,
                    'is_primary' => $i === 0,
                    'sort_order' => $i,
                    'created_at' => now(),
                ]);
            }
        }

        $this->command?->info("تم توليد صور لـ{$listings->count()} إعلاناً.");
    }

    private function svg(string $title, int $paletteIndex): string
    {
        [$from, $to] = self::PALETTE[$paletteIndex];
        $label = htmlspecialchars(mb_substr($title, 0, 26), ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="800" height="600">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$from.'"/>'
            .'<stop offset="1" stop-color="'.$to.'"/>'
            .'</linearGradient></defs>'
            .'<rect width="800" height="600" fill="url(#g)"/>'
            .'<text x="400" y="312" text-anchor="middle" fill="#ffffff" fill-opacity="0.9" '
            .'font-family="sans-serif" font-size="32" direction="rtl">'.$label.'</text>'
            .'</svg>';
    }
}

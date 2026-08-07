<?php

namespace App\Modules\Shared\Database\Seeders;

use App\Modules\Shared\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    /** المحافظة => أبرز مديرياتها */
    private const GOVERNORATES = [
        'أمانة العاصمة' => ['التحرير', 'شعوب', 'معين', 'السبعين', 'بني الحارث', 'الثورة'],
        'صنعاء' => ['بني مطر', 'همدان', 'أرحب', 'سنحان'],
        'عدن' => ['كريتر', 'المعلا', 'التواهي', 'خور مكسر', 'الشيخ عثمان', 'المنصورة', 'دار سعد'],
        'تعز' => ['المظفر', 'القاهرة', 'صالة', 'التربة', 'المخا'],
        'الحديدة' => ['الحوك', 'الميناء', 'الحالي', 'باجل', 'زبيد'],
        'إب' => ['المشنة', 'الظهار', 'يريم', 'العدين', 'جبلة'],
        'حضرموت' => ['المكلا', 'سيئون', 'تريم', 'الشحر', 'غيل باوزير'],
        'ذمار' => ['ذمار', 'عنس', 'الحداء', 'معبر'],
        'حجة' => ['حجة', 'عبس', 'المحابشة', 'حرض'],
        'مأرب' => ['مأرب', 'الوادي', 'حريب'],
        'لحج' => ['الحوطة', 'تبن', 'ردفان', 'يافع'],
        'أبين' => ['زنجبار', 'خنفر', 'جعار', 'لودر'],
        'شبوة' => ['عتق', 'بيحان', 'الروضة', 'رضوم'],
        'صعدة' => ['صعدة', 'سحار', 'رازح'],
        'البيضاء' => ['البيضاء', 'رداع', 'مكيراس'],
        'عمران' => ['عمران', 'حوث', 'ريدة'],
        'المحويت' => ['المحويت', 'الطويلة', 'شبام كوكبان'],
        'الضالع' => ['الضالع', 'قعطبة', 'دمت'],
        'ريمة' => ['الجبين', 'كسمة'],
        'الجوف' => ['الحزم', 'المتون'],
        'المهرة' => ['الغيضة', 'قشن', 'سيحوت'],
        'سقطرى' => ['حديبو', 'قلنسية'],
    ];

    public function run(): void
    {
        $country = Location::updateOrCreate(
            ['slug' => 'yemen'],
            [
                'name_ar' => 'اليمن',
                'level' => 'country',
                'path' => '',
                'sort_order' => 0,
            ]
        );

        $order = 1;

        foreach (self::GOVERNORATES as $governorateName => $districts) {
            $governorate = Location::updateOrCreate(
                ['slug' => Str::slug($this->transliterate($governorateName)) . '-gov'],
                [
                    'name_ar' => $governorateName,
                    'parent_id' => $country->id,
                    'level' => 'governorate',
                    'path' => '',
                    'sort_order' => $order++,
                ]
            );

            $districtOrder = 1;

            foreach ($districts as $districtName) {
                Location::updateOrCreate(
                    ['slug' => $governorate->slug . '-' . $districtOrder],
                    [
                        'name_ar' => $districtName,
                        'parent_id' => $governorate->id,
                        'level' => 'district',
                        'path' => '',
                        'sort_order' => $districtOrder++,
                    ]
                );
            }
        }
    }

    /** تحويل مبسّط للحروف العربية إلى لاتينية لتوليد slug مقروء */
    private function transliterate(string $text): string
    {
        $map = [
            'ا' => 'a', 'أ' => 'a', 'إ' => 'a', 'آ' => 'a', 'ب' => 'b', 'ت' => 't',
            'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh',
            'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd',
            'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q',
            'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'ة' => 'h',
            'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ء' => '', 'ئ' => 'y', 'ؤ' => 'w',
            ' ' => '-',
        ];

        return strtr($text, $map);
    }
}

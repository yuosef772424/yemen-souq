<?php

namespace App\Modules\Shared\Database\Seeders;

use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use App\Modules\Shared\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /** التصنيف الرئيسي => [slug, أيقونة, التصنيفات الفرعية] */
    private const TREE = [
        ['سيارات ومركبات', 'vehicles', 'car', ['سيارات للبيع', 'دراجات نارية', 'قطع غيار', 'شاحنات ومعدات']],
        ['عقارات', 'real-estate', 'home', ['شقق للبيع', 'شقق للإيجار', 'أراضي', 'محلات ومكاتب']],
        ['أجهزة إلكترونية', 'electronics', 'smartphone', ['جوالات', 'حاسبات ولابتوبات', 'تلفزيونات', 'كاميرات']],
        ['أثاث ومستلزمات منزل', 'furniture', 'sofa', ['غرف نوم', 'مجالس وكنب', 'أجهزة منزلية', 'مطابخ']],
        ['أزياء ومقتنيات', 'fashion', 'shirt', ['ملابس رجالية', 'ملابس نسائية', 'ساعات', 'عطور']],
        ['خدمات', 'services', 'wrench', ['نقل وشحن', 'صيانة', 'تعليم ودروس', 'تصميم وبرمجة']],
        ['وظائف', 'jobs', 'briefcase', ['مطلوب موظفين', 'أبحث عن عمل']],
        ['حيوانات ومزارع', 'animals', 'leaf', ['مواشي', 'طيور', 'مستلزمات زراعية']],
    ];

    /** الصفات المتاحة، ثم ربطها بالتصنيفات */
    private const ATTRIBUTES = [
        // الشركة المصنعة قبل سنة الصنع في كل مكان تُستعمل فيه: هي الفلتر
        // الأول عملياً عند شراء مركبة — «تويوتا» يضيّق قبل «٢٠١٨»، والعكس نادر
        ['manufacturer', 'الشركة المصنعة', 'option', null, [
            'تويوتا', 'هيونداي', 'كيا', 'نيسان', 'ميتسوبيشي', 'مرسيدس', 'بي إم دبليو',
            'لكزس', 'فورد', 'شيفروليه', 'هوندا', 'مازدا', 'أخرى',
        ]],
        ['year', 'سنة الصنع', 'number', 'سنة', null],
        ['mileage', 'الممشى', 'number', 'كم', null],
        ['transmission', 'ناقل الحركة', 'option', null, ['أوتوماتيك', 'عادي']],
        ['fuel', 'نوع الوقود', 'option', null, ['بنزين', 'ديزل', 'كهرباء', 'هجين']],
        ['rooms', 'عدد الغرف', 'number', 'غرفة', null],
        ['bathrooms', 'عدد الحمامات', 'number', 'حمام', null],
        ['area', 'المساحة', 'number', 'م²', null],
        ['floor', 'الطابق', 'number', null, null],
        ['furnished', 'مفروش', 'boolean', null, null],
        ['storage', 'سعة التخزين', 'option', null, ['64 جيجا', '128 جيجا', '256 جيجا', '512 جيجا', '1 تيرا']],
        ['ram', 'الذاكرة العشوائية', 'option', null, ['4 جيجا', '8 جيجا', '16 جيجا', '32 جيجا']],
        ['warranty', 'يوجد ضمان', 'boolean', null, null],
    ];

    /** slug التصنيف الفرعي => مفاتيح الصفات المرتبطة به */
    private const CATEGORY_ATTRIBUTES = [
        'سيارات للبيع' => ['manufacturer', 'year', 'mileage', 'transmission', 'fuel'],
        'دراجات نارية' => ['year', 'mileage'],
        'شاحنات ومعدات' => ['manufacturer', 'year', 'mileage', 'fuel'],
        'شقق للبيع' => ['rooms', 'bathrooms', 'area', 'floor', 'furnished'],
        'شقق للإيجار' => ['rooms', 'bathrooms', 'area', 'floor', 'furnished'],
        'أراضي' => ['area'],
        'محلات ومكاتب' => ['area', 'floor'],
        'جوالات' => ['storage', 'ram', 'warranty'],
        'حاسبات ولابتوبات' => ['storage', 'ram', 'warranty'],
        'تلفزيونات' => ['warranty'],
        'كاميرات' => ['warranty'],
    ];

    public function run(): void
    {
        $this->seedAttributes();
        $this->seedCategories();
    }

    private function seedAttributes(): void
    {
        foreach (self::ATTRIBUTES as [$key, $nameAr, $type, $unit, $options]) {
            $attribute = Attribute::updateOrCreate(
                ['attr_key' => $key],
                ['name_ar' => $nameAr, 'data_type' => $type, 'unit' => $unit]
            );

            foreach ($options ?? [] as $index => $optionValue) {
                AttributeOption::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value_ar' => $optionValue],
                    ['sort_order' => $index + 1]
                );
            }
        }
    }

    private function seedCategories(): void
    {
        $order = 1;

        foreach (self::TREE as [$name, $slug, $icon, $children]) {
            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_ar' => $name,
                    'icon' => $icon,
                    'path' => '',
                    'sort_order' => $order++,
                ]
            );

            $childOrder = 1;

            foreach ($children as $childName) {
                $child = Category::updateOrCreate(
                    ['slug' => $slug.'-'.$childOrder],
                    [
                        'name_ar' => $childName,
                        'parent_id' => $parent->id,
                        'path' => '',
                        'sort_order' => $childOrder++,
                    ]
                );

                $this->attachAttributes($child, $childName);
            }
        }
    }

    private function attachAttributes(Category $category, string $name): void
    {
        $keys = self::CATEGORY_ATTRIBUTES[$name] ?? [];

        if ($keys === []) {
            return;
        }

        $ids = Attribute::whereIn('attr_key', $keys)->pluck('id', 'attr_key');
        $sync = [];
        $order = 1;

        foreach ($keys as $key) {
            if (isset($ids[$key])) {
                $sync[$ids[$key]] = ['is_filterable' => true, 'sort_order' => $order++];
            }
        }

        $category->attributes()->sync($sync);
    }
}

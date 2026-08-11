<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * توصيل الخصائص الخاصة بكل تصنيف بدورة حياة الإعلان.
 *
 * ═══ لماذا كانت معطَّلة ═══
 *
 * الجداول الخمسة والموديلان موجودة منذ البداية، والبذرة تزرع اثنتي عشرة
 * صفة و30 ارتباطاً بالتصنيفات. ومع ذلك كان listing_attribute_values
 * فارغاً تماماً: لا نموذج إنشاء يكتب فيه، ولا صفحة تقرأ منه. تصميم كامل
 * بلا سطر تنفيذ.
 *
 * ولهذا أثر يتجاوز الشكل: value_number فُصل عن value_string عمداً لتمكين
 * الفلترة الرقمية («ممشى أقل من 100 ألف»)، وهي أهم ما يميّز الإعلانات
 * المبوّبة عن قائمة نصوص. عمود بلا كاتب يجعل تلك الفلترة مستحيلة.
 *
 * ═══ لماذا صنف واحد للتحقق والكتابة والقراءة ═══
 *
 * الثلاثة تشترك في سؤال واحد: أي صفات يملكها هذا التصنيف؟ وفصلها إلى
 * ثلاثة أصناف يعني ثلاث نسخ من ذلك الاستعلام تتفرّق عند أول تعديل.
 *
 * ═══ التصفية بالتصنيف على الخادم لا في المتصفح ═══
 *
 * النموذج يعرض حقول كل التصنيفات ويُخفي غير المعني بـAlpine. والإخفاء
 * بالعرض لا يمنع الإرسال — حقل مخفي يُرسَل مع النموذج. لذلك تُقصى هنا
 * كل صفة لا يملكها التصنيف المختار: بلا ذلك يكتب المعلن «عدد الغرف»
 * على سيارة بتعديل بسيط في المتصفح.
 */
class ListingAttributes
{
    /**
     * صفات التصنيف مرتّبة، مع خياراتها.
     *
     * @return Collection<int, Attribute>
     */
    public function forCategory(?int $categoryId): Collection
    {
        if ($categoryId === null) {
            return collect();
        }

        $category = Category::with(['attributes.options'])->find($categoryId);

        return $category?->attributes ?? collect();
    }

    /**
     * كل الصفات مجمّعة بالتصنيف — لبناء النموذج دفعة واحدة.
     *
     * استعلام واحد لكل التصنيفات لا استعلام لكل تصنيف: النموذج يعرض
     * الثلاثين ارتباطاً معاً، وتحميلها فرادى يعني ثلاثين رحلة.
     *
     * @return Collection<int, Collection<int, Attribute>>
     */
    public function groupedByCategory(): Collection
    {
        return Category::has('attributes')
            ->with(['attributes.options'])
            ->get()
            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->attributes]);
    }

    /**
     * قواعد تحقق مشتقّة من تعريف الصفة نفسها.
     *
     * القاعدة تُبنى من data_type وmin_value وmax_value وis_required في
     * قاعدة البيانات، لا تُكتب يدوياً في FormRequest. إضافة صفة جديدة
     * بالبذرة تصير محقَّقة بلا لمس كود التحقق.
     *
     * @return array<string, list<string>>
     */
    public function rules(?int $categoryId): array
    {
        $rules = ['attributes' => ['nullable', 'array']];

        foreach ($this->forCategory($categoryId) as $attribute) {
            $key = "attributes.{$attribute->id}";
            $required = (bool) ($attribute->pivot->is_required ?? false);

            $rules[$key] = array_merge(
                [$required ? 'required' : 'nullable'],
                $this->typeRules($attribute)
            );

            $rules = array_merge($rules, $this->itemRules($attribute));
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function typeRules(Attribute $attribute): array
    {
        return match ($attribute->data_type) {
            'number' => array_filter([
                'numeric',
                $attribute->min_value !== null ? 'min:'.$attribute->min_value : null,
                $attribute->max_value !== null ? 'max:'.$attribute->max_value : null,
            ]),
            'boolean' => ['boolean'],
            'date' => ['date'],
            // الخيار يُتحقَّق مقابل خيارات هذه الصفة وحدها لا كل الخيارات:
            // بلا القيد الثاني يمرّ «أوتوماتيك» قيمةً لسعة التخزين
            'option' => ['integer', 'exists:attribute_options,id,attribute_id,'.$attribute->id],
            'multi_option' => ['array'],
            default => ['string', 'max:255'],
        };
    }

    /**
     * قواعد عناصر الخيارات المتعددة.
     *
     * منفصلة عن typeRules لأن مفتاحها مختلف: القاعدة على
     * attributes.<id> تصف المصفوفة، وهذه تصف كل عنصر فيها بـ
     * attributes.<id>.* — ولارافل يحتاج المفتاحين معاً.
     *
     * @return array<string, list<string>>
     */
    private function itemRules(Attribute $attribute): array
    {
        if (! $attribute->isMulti()) {
            return [];
        }

        return [
            "attributes.{$attribute->id}.*" => [
                'integer',
                'exists:attribute_options,id,attribute_id,'.$attribute->id,
            ],
        ];
    }

    /**
     * كتابة القيم المرسَلة، مقصورةً على صفات التصنيف المختار.
     *
     * @param  array<int|string, mixed>  $input
     */
    public function write(Listing $listing, array $input): void
    {
        $allowed = $this->forCategory($listing->category_id);

        $rows = [];
        $multiRows = [];

        foreach ($allowed as $attribute) {
            // الخيارات المتعددة في جدول مستقل: المفتاح في جدول القيم
            // مركّب من (listing, attribute) فلا يحمل إلا قيمة واحدة
            // لكل خاصية
            if ($attribute->isMulti()) {
                $multiRows = array_merge(
                    $multiRows,
                    $this->multiRows($listing, $attribute, $input[$attribute->id] ?? null)
                );

                continue;
            }

            $value = $input[$attribute->id] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            /**
             * كل الأعمدة في كل صف، والمعنيّ يُكتب فوق null الخاص به.
             *
             * الإدراج الجماعي في لارافل يبني الاستعلام من مفاتيح الصف
             * الأول ويطبّقها على الباقي. فصفٌّ بـvalue_number وآخر
             * بـoption_id يُنتجان إدراجاً يكتب الأول ويُفرغ الثاني —
             * وهو ما وقع فعلاً: صف الخيار كُتب وoption_id فيه null.
             */
            $rows[] = [
                'listing_id' => $listing->id,
                'attribute_id' => $attribute->id,
                'value_string' => null,
                'value_number' => null,
                'value_boolean' => null,
                'value_date' => null,
                'option_id' => null,
                $attribute->valueColumn() => $this->cast($attribute, $value),
            ];
        }

        $allowedIds = $allowed->pluck('id');

        DB::transaction(function () use ($listing, $allowedIds, $rows, $multiRows): void {
            // الحذف ثم الإدراج لا upsert: التعديل قد يُفرغ صفة كانت
            // مملوءة، وupsert وحده يُبقي القيمة القديمة إلى الأبد
            DB::table('listing_attribute_values')
                ->where('listing_id', $listing->id)
                ->whereIn('attribute_id', $allowedIds)
                ->delete();

            DB::table('listing_multi_options')
                ->where('listing_id', $listing->id)
                ->whereIn('attribute_id', $allowedIds)
                ->delete();

            if ($rows !== []) {
                DB::table('listing_attribute_values')->insert($rows);
            }

            if ($multiRows !== []) {
                DB::table('listing_multi_options')->insert($multiRows);
            }
        });
    }

    /**
     * صفوف الخيارات المتعددة، مقصورةً على خيارات هذه الخاصية.
     *
     * التحقق يرفض الخيار الغريب قبل الوصول إلى هنا، والتصفية مكرَّرة
     * بقصد: write يُستدعى من مسار الاستيراد لاحقاً بلا FormRequest،
     * والمفتاح الأساسي المركّب يجعل خياراً مكرَّراً خطأً في قاعدة
     * البيانات لا تجاهلاً.
     *
     * @return list<array<string, int>>
     */
    private function multiRows(Listing $listing, Attribute $attribute, mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $valid = $attribute->options->pluck('id')->all();

        $ids = [];

        foreach ($value as $item) {
            if (is_numeric($item) && in_array((int) $item, $valid, true)) {
                $ids[(int) $item] = true;
            }
        }

        return array_map(static fn (int $id): array => [
            'listing_id' => $listing->id,
            'attribute_id' => $attribute->id,
            'option_id' => $id,
        ], array_keys($ids));
    }

    private function cast(Attribute $attribute, mixed $value): mixed
    {
        return match ($attribute->data_type) {
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'option' => (int) $value,
            'date' => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * قيم الإعلان جاهزةً للعرض: التسمية العربية، والقيمة، والوحدة.
     *
     * @return list<array{label: string, value: string, unit: ?string}>
     */
    public function forDisplay(Listing $listing): array
    {
        $values = DB::table('listing_attribute_values')
            ->where('listing_id', $listing->id)
            ->get()
            ->keyBy('attribute_id');

        $multi = DB::table('listing_multi_options')
            ->where('listing_id', $listing->id)
            ->get()
            ->groupBy('attribute_id');

        if ($values->isEmpty() && $multi->isEmpty()) {
            return [];
        }

        $display = [];

        foreach ($this->forCategory($listing->category_id) as $attribute) {
            if ($attribute->isMulti()) {
                $chosen = $this->chosenOptions($attribute, $multi->get($attribute->id));

                if ($chosen !== '') {
                    $display[] = [
                        'label' => $attribute->name_ar,
                        'value' => $chosen,
                        'unit' => null,
                    ];
                }

                continue;
            }

            $row = $values->get($attribute->id);

            if ($row === null) {
                continue;
            }

            $formatted = $this->format($attribute, $row);

            if ($formatted === null) {
                continue;
            }

            $display[] = [
                'label' => $attribute->name_ar,
                'value' => $formatted,
                'unit' => $attribute->unit,
            ];
        }

        return $display;
    }

    /**
     * الخيارات المختارة بترتيب تعريفها لا بترتيب اختيار المعلن.
     *
     * ترتيب الإدخال يجعل إعلانين بنفس المواصفات يبدوان مختلفين، وهو
     * فارق بلا معنى للقارئ.
     *
     * @param  Collection<int, object>|null  $rows
     */
    private function chosenOptions(Attribute $attribute, ?Collection $rows): string
    {
        if ($rows === null || $rows->isEmpty()) {
            return '';
        }

        $ids = $rows->pluck('option_id')->all();

        return $attribute->options
            ->whereIn('id', $ids)
            ->pluck('value_ar')
            ->implode('، ');
    }

    private function format(Attribute $attribute, object $row): ?string
    {
        return match ($attribute->data_type) {
            /**
             * بلا كسور زائدة وبلا فاصل آلاف.
             *
             * الكسور: العمود decimal(18,4) فيُعيد 2018.0000 لسنة صنع.
             *
             * والفاصل: أُزيل بعد أن أنتج «2,018» لسنة الصنع. ولا سبيل
             * إلى التفريق من السكيمة — سنة الصنع والممشى كلاهما number
             * بلا ما يميّزهما. فالخيار بين سنة مكسورة الشكل وممشى أقل
             * تنسيقاً، والثاني أهون: التنسيق الخاطئ يُقرأ كعطل، والخام
             * يُقرأ كرقم.
             */
            'number' => $row->value_number === null
                ? null
                : rtrim(rtrim(number_format((float) $row->value_number, 4, '.', ''), '0'), '.'),
            'boolean' => $row->value_boolean === null
                ? null
                : ((bool) $row->value_boolean ? 'نعم' : 'لا'),
            'date' => $row->value_date,
            'option' => $attribute->options->firstWhere('id', $row->option_id)?->value_ar,
            default => $row->value_string,
        };
    }
}

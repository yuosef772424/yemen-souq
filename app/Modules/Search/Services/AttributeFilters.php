<?php

namespace App\Modules\Search\Services;

use App\Modules\Search\DTO\AttributeFilter;
use App\Modules\Shared\Models\Attribute;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * فلترة النتائج بخصائص التصنيف.
 *
 * ═══ الغاية التي فُصل العمود لأجلها ═══
 *
 * value_number منفصل عن value_string في السكيمة منذ البداية، وتعليق
 * الهجرة يذكر السبب: «منفصل لتمكين الفلترة الرقمية». وبقي بلا قارئ
 * يفلتر — أي أن السبب لم يتحقق. هذا الملف هو ما يحققه.
 *
 * ═══ EXISTS لا JOIN ═══
 *
 * الفلترة بخاصيتين تعني صفّين في listing_attribute_values لنفس الإعلان.
 * وJOIN عليهما يضاعف صفوف النتيجة فيحتاج DISTINCT، وDISTINCT مع
 * ORDER BY على تعبيرات الصلة يمنع الفهرس ويكسر الترتيب. وEXISTS يسأل
 * سؤالاً منطقياً بلا أن يمسّ عدد الصفوف.
 *
 * والفهرس ['attribute_id', 'value_number'] موجود على الجدول أصلاً —
 * الاستعلام الفرعي مبنيّ ليستعمله: مساواة على الأول ونطاق على الثاني.
 *
 * ═══ النوع يُحسم هنا لا عند قراءة الرابط ═══
 *
 * SearchCriteria يقرأ الشكل (نطاق أم مطابقة) ولا يعرف النوع. والمطابقة
 * بين الاثنين تجري هنا حيث تُعرف data_type: نطاق على خاصية خيارات
 * يُهمَل، ومطابقة على خاصية رقمية تُهمَل كذلك — بلا خطأ، فالنتيجة
 * بحث أوسع لا صفحة معطوبة.
 */
final class AttributeFilters
{
    /**
     * @param  list<AttributeFilter>  $filters
     */
    public function apply(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        $attributes = $this->resolve($filters);

        foreach ($filters as $filter) {
            $attribute = $attributes->get($filter->key);

            if ($attribute === null) {
                continue;
            }

            $this->applyOne($query, $attribute, $filter);
        }
    }

    /**
     * استعلام واحد لكل المفاتيح لا استعلام لكل فلتر.
     *
     * @param  list<AttributeFilter>  $filters
     * @return Collection<string, Attribute>
     */
    private function resolve(array $filters): Collection
    {
        $keys = array_values(array_unique(array_map(
            static fn (AttributeFilter $filter): string => $filter->key,
            $filters
        )));

        return Attribute::whereIn('attr_key', $keys)->get()->keyBy('attr_key');
    }

    private function applyOne(Builder $query, Attribute $attribute, AttributeFilter $filter): void
    {
        $matches = match ($attribute->data_type) {
            'number' => $filter->isRange(),
            'option', 'boolean' => $filter->isEquality(),
            default => false,
        };

        if (! $matches) {
            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($attribute, $filter): void {
            $sub->select(DB::raw(1))
                ->from('listing_attribute_values')
                ->whereColumn('listing_attribute_values.listing_id', 'listings.id')
                ->where('listing_attribute_values.attribute_id', $attribute->id);

            match ($attribute->data_type) {
                'number' => $this->applyRange($sub, $filter),
                'option' => $sub->where('listing_attribute_values.option_id', $filter->equals),
                default => $sub->where('listing_attribute_values.value_boolean', $filter->equals === 1),
            };
        });
    }

    private function applyRange(QueryBuilder $sub, AttributeFilter $filter): void
    {
        if ($filter->min !== null) {
            $sub->where('listing_attribute_values.value_number', '>=', $filter->min);
        }

        if ($filter->max !== null) {
            $sub->where('listing_attribute_values.value_number', '<=', $filter->max);
        }
    }
}

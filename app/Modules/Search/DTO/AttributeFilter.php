<?php

namespace App\Modules\Search\DTO;

/**
 * فلتر واحد على خاصية: إمّا نطاق رقمي أو مطابقة تامة.
 *
 * الشكلان في صنف واحد لا صنفين: الرابط يحمل الاثنين تحت نفس المعامل
 * (attr[mileage][max] وattr[transmission]=3)، والتفريق بينهما يتم بنوع
 * الخاصية في قاعدة البيانات لا بشكل المدخل. صنفان يعني قراءة المعامل
 * مرتين وتخميناً مبكراً للنوع قبل معرفته.
 *
 * والمفتاح هو attr_key لا المعرّف الرقمي: الرابط يُنسخ ويُشارَك ويُفهرَس،
 * وattr[mileage][max]=100000 يبقى مقروءاً ومستقراً، بينما attr[7] يتعلّق
 * بترتيب البذرة في قاعدة بعينها.
 */
final readonly class AttributeFilter
{
    /**
     * @param  list<int>  $anyOf  معرّفات خيارات — يكفي واحد منها
     */
    public function __construct(
        public string $key,
        public ?float $min = null,
        public ?float $max = null,
        public ?int $equals = null,
        public array $anyOf = [],
    ) {}

    public function isRange(): bool
    {
        return $this->min !== null || $this->max !== null;
    }

    public function isEquality(): bool
    {
        return $this->equals !== null;
    }

    /**
     * قائمة خيارات — دلالتها «أيّ منها» لا «كلها».
     *
     * لماذا OR لا AND: الخيارات المتعددة تصف ما تملكه السلعة (مكيّف،
     * فتحة سقف، كاميرا خلفية)، والباحث الذي يؤشّر ثلاثة يقصد «يهمّني
     * أيٌّ منها» غالباً لا «أريد الثلاثة معاً». واشتراط الكل يُرجع صفراً
     * في أغلب الحالات — وفلتر يُفرغ النتائج يُهجَر.
     */
    public function isAnyOf(): bool
    {
        return $this->anyOf !== [];
    }
}

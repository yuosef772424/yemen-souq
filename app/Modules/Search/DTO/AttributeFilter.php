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
    public function __construct(
        public string $key,
        public ?float $min = null,
        public ?float $max = null,
        public ?int $equals = null,
    ) {}

    public function isRange(): bool
    {
        return $this->min !== null || $this->max !== null;
    }

    public function isEquality(): bool
    {
        return $this->equals !== null;
    }
}

<?php

namespace App\Modules\Api\Http\Resources;

use Illuminate\Http\Request;

/**
 * صفحة الإعلان — البطاقة زائد ما لا يُعرض في قائمة.
 *
 * ═══ لماذا امتداد لا حقول اختيارية في البطاقة ═══
 *
 * البديل المرفوض: `ListingResource` واحد بأعلام (`?with=description`).
 * يعمل، لكنه يجعل شكل الاستجابة يتغيّر بمعامل في الرابط — فالتطبيق لا
 * يستطيع كتابة نوعٍ واحد لما يستقبله، ويصير كل حقل احتمالاً. صنفان
 * يعني عقدين ثابتين.
 *
 * ═══ رقم التواصل غائب هنا بقصد ═══
 *
 * لا `contact_phone` في هذا الشكل ولو كان `hide_phone` مطفأً. الرقم
 * يُجلب من `POST /listings/{listing}/contact` وحده — لنفس سبب
 * `ContactRevealController`: كشف الرقم أقوى إشارة نية شراء في المنصة،
 * وإرساله مع الصفحة يُلغي قياسه ويفتح حصاد الأرقام آلياً بزحفٍ على
 * نقطة نهاية عامة.
 *
 * ═══ والمواصفات مُنسَّقة لا خام ═══
 *
 * `ListingAttributes::forDisplay` يُخرج (تسمية، قيمة، وحدة) بالعربية.
 * الشكل الخام — معرّف صفة ومعرّف خيار — يجبر التطبيق على جلب جدول
 * الخصائص ليعرض «أوتوماتيك»، وهو استعلام ثانٍ لكل إعلان يُفتح.
 */
class ListingDetailResource extends ListingResource
{
    /**
     * المواصفات والمكافئات تُمرَّر في الباني لا عبر ‎additional()‎.
     *
     * `additional()` تدمج ما يُعطى في **جذر** الاستجابة بجانب `data`،
     * فقراءتها من داخل `toArray` تُخرج الحقل مرتين: مرة داخل الإعلان
     * ومرة خارجه. والباني يجعل التبعية معلَنة — من ينسى تمريرها يرى
     * حقلاً فارغاً لا حقلاً مكرَّراً.
     *
     * ═══ ولا تسمّها ‎$attributes‎ ═══
     *
     * الاسم محجوز. `JsonResource::toAttributes` في لارافل 12 يفحص
     * ‎property_exists($this, 'attributes')‎ ويُرجع الخاصية **بدل**
     * استدعاء `toArray`. فخاصية بهذا الاسم تجعل المورد كله يُصيَّر
     * إلى قيمتها — أي `[]` هنا.
     *
     * والعطل صامت تماماً: لا خطأ، و`toArray` تعمل صحيحةً لو نوديت
     * مباشرةً، والاستجابة تعود 201 بجسدٍ فيه `"data": []`.
     *
     * @param  list<array{label: string, value: string, unit: ?string}>  $specs
     * @param  list<array<string, mixed>>  $equivalents
     */
    public function __construct(
        $resource,
        private readonly array $specs = [],
        private readonly array $equivalents = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'description' => $this->description,
            'hide_phone' => (bool) $this->hide_phone,

            'images' => ListingImageResource::collection($this->whenLoaded('images')),

            'seller' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->displayName(),
                'member_since' => $this->user->created_at?->toIso8601String(),
            ]),

            // تُحقَن من المتحكّم — الموديل لا يعرف الخدمة التي تقرؤها
            'attributes' => $this->specs,
            'price_equivalents' => $this->equivalents,

            'renewed_at' => $this->renewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

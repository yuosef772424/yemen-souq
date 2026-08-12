<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Modules\Catalog\Services\DuplicateListingGuard;
use Illuminate\Validation\Validator;

/**
 * رفض الإعلان المكرَّر — في طلبَي النشر والتعديل معاً.
 *
 * ═══ لماذا في الطلب لا في المتحكّم ═══
 *
 * المتحكّمان اثنان (الويب و`api/v1`) والطلبان مشتركان بينهما. ووضع
 * الحارس هنا يعني مساراً واحداً للقرار ورسالةً واحدة، بدل نسختين
 * تتفرّقان عند أول تعديل.
 *
 * وبعد بقية القواعد لا قبلها: البصمة تُحسب من تصنيفٍ وموقعٍ وسعر قد
 * تكون كلها غير صالحة. و`after` يُنادى حتى حين تسقط القواعد، فالخروج
 * المبكر عند وجود خطأ شرطٌ لا زينة — `validated()` نفسها ترمي متى كانت
 * هناك رسالة واحدة.
 *
 * ═══ ولماذا الخطأ على `title` ═══
 *
 * لا حقل في النموذج اسمه «تكرار». والعنوان أقرب ما يُقرأ منه المعنى،
 * وهو أول ما يغيّره من أراد نشر سلعةٍ ثانية تشبه الأولى.
 */
trait RejectsDuplicateListings
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $duplicate = app(DuplicateListingGuard::class)->duplicateOf(
                $user,
                $validator->validated(),
                $this->currentListingId(),
            );

            if ($duplicate === null) {
                return;
            }

            $validator->errors()->add('title', $this->duplicateMessage($duplicate->id));
        });
    }

    /** الإعلان الجاري تعديله — لا يكون نسخةً من نفسه. النشر يُرجع null */
    protected function currentListingId(): ?int
    {
        $listing = $this->route('listing');

        return is_object($listing) ? $listing->getKey() : null;
    }

    private function duplicateMessage(int $duplicateId): string
    {
        return "لديك إعلان منشور بنفس هذه البيانات (رقم {$duplicateId}). "
            .'جدّده من «إعلاناتي» ليعود إلى المقدمة، أو غيّر شيئاً في هذا الإعلان.';
    }
}

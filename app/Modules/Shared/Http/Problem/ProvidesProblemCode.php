<?php

namespace App\Modules\Shared\Http\Problem;

/**
 * استثناءٌ يحدّد رمز المشكلة بنفسه.
 *
 * الحالة التي وُجد لها: `ValidationException` تعود 422 بالرمز العام
 * `validation_failed`، لكن خطأ حقلٍ بعينه قد يحتاج رمزاً يفرّقه التطبيق
 * (`phone_taken`). صنفٌ فرعي من ValidationException ينفّذ هذه الواجهة
 * يحتفظ بسلوك لارافل كله (إعادة التوجيه في الويب، `errors`) ويُغيّر
 * الرمز في ‎/api‎ وحده.
 *
 * الخدمات التي لا تحتاج وراثة تستعمل `ApiProblemException` مباشرةً.
 */
interface ProvidesProblemCode
{
    public function problemCode(): ProblemCode|string;
}

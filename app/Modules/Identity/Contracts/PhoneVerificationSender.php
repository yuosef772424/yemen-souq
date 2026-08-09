<?php

namespace App\Modules\Identity\Contracts;

/**
 * قناة توصيل رمز التحقق.
 *
 * الغرض من العقد: تبديل مزوّد الرسائل لا يمس منطق التحقق إطلاقاً.
 * التجزئة والصلاحية وعدّ المحاولات كلها في الخدمة، والمُرسل يوصّل فقط.
 */
interface PhoneVerificationSender
{
    public function send(string $phone, string $code): void;

    /**
     * هل تُعرَض الرمز للمستخدم في الواجهة؟
     *
     * القرار في المُرسل لا في المتحكم: من يعرف أنه لا يوصّل فعلاً هو
     * الوحيد الذي يحق له أن يقول "اعرضه على الشاشة".
     */
    public function displaysCode(): bool;
}
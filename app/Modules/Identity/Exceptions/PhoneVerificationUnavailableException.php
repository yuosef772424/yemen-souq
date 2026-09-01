<?php

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * القناة نفسها غير جاهزة — لا خطأ من المستخدم.
 *
 * تُميَّز عن RuntimeException العادية (سقف الإرسال) لأن رسالتها تسمّي
 * تفاصيل تنفيذ (DisplaySender، اسم متغيّر بيئة) لا يجوز أن تصل مستخدماً
 * حقيقياً — من يقرأها يعرف بنية الخادم الداخلية بلا فائدة، ومن لا
 * يفهمها يرى عطلاً يبدو أعمق من مجرّد "التوثيق متوقّف الآن".
 *
 * المتحكّمات تلتقطها أولاً وتعرض رسالة عامة، بعد أن تُبلَّغ عبر report()
 * ليبقى السبب الحقيقي في السجلّ لا في وجه المستخدم.
 */
final class PhoneVerificationUnavailableException extends RuntimeException
{
}

<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTO\PushMessage;

/**
 * عقد مزوّد الدفع.
 *
 * ═══ لماذا يُرجع رموزاً ═══
 *
 * كل مزوّد دفع يردّ على الرمز الميت بعلامة صريحة (NotRegistered في FCM،
 * Unregistered في APNs)، والعقد بلا قيمة راجعة يرمي هذه المعلومة.
 * ونتيجتها تراكمية: رمز جهاز حُذف عنه التطبيق يبقى في الجدول إلى الأبد،
 * ومع الوقت يصير معظم ما يُرسَل إلى العدم — فيبطؤ الطابور ويرتفع معدّل
 * الفشل عند المزوّد حتى يُقيّد الحساب.
 *
 * فالمرسِل يُرجع ما رفضه المزوّد، وDeviceRegistrar يحذفه. هذه هي الحلقة
 * التي تُبقي جدول الأجهزة نظيفاً بلا صيانة يدوية.
 */
interface PushSender
{
    /**
     * @param  list<string>  $tokens
     * @return list<string> الرموز التي رفضها المزوّد كميتة — تُحذف
     */
    public function send(array $tokens, PushMessage $message): array;
}

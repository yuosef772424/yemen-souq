<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Models\AdminActionRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * كاتب سجل المساءلة.
 *
 * خدمة واحدة لا سطر إدراج في كل متحكّم: الكتابة المنسية في إجراء واحد
 * تعني ثغرة في السجل — وسجل مساءلة به ثغرة أسوأ من لا سجل، لأنه يُقرأ
 * كأنه كامل. وجودها في موضع واحد يعني أن كل إجراء يمرّ من هنا أو لا
 * يُنفَّذ، وأن إضافة عمود للسجل لاحقاً تعديل ملف واحد.
 *
 * meta تُخزَّن الحالة قبل وبعد. لماذا: «رفض إعلاناً» بلا حالة سابقة لا
 * يخبر إن كان الإعلان منشوراً أو مسودة — وهو الفرق بين إجراء وقرار
 * بلا معنى.
 */
final class AuditLogger
{
    public function log(
        ?User $admin,
        AdminAction $action,
        ?Model $entity = null,
        ?string $reason = null,
        array $meta = [],
    ): AdminActionRecord {
        return AdminActionRecord::create([
            'admin_id' => $admin?->id,
            'action' => $action->value,
            'entity_type' => $entity === null ? null : $this->typeOf($entity),
            'entity_id' => $entity?->getKey(),
            'reason' => $reason,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * اسم قصير بلا مساحة الاسم.
     *
     * البديل المرفوض: تخزين اسم الصنف الكامل. مرفوض لأن نقل موديل بين
     * وحدتين يجعل كل صفوف السجل القديمة تشير إلى صنف غير موجود، فتفقد
     * القدرة على تصفية السجل بنوع الكائن. الاسم القصير يعيش أطول من
     * مساحة الاسم.
     */
    private function typeOf(Model $entity): string
    {
        return mb_strtolower(class_basename($entity));
    }
}

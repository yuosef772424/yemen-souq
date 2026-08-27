<?php

namespace App\Modules\Notifications\Enums;

/**
 * فهرس أنواع الإشعارات.
 *
 * ═══ لماذا تعداد لا نص حر ═══
 *
 * نفس حجّة EventName وAdminAction: النوع يُكتب في عمود، ويُقرأ لاحقاً
 * في التفضيلات وفي تصفية التطبيق. خطأ إملائي واحد في نص حرّ يُنتج نوعاً
 * لا يطابقه أي تفضيل — فيصل الإشعار إلى من أوقفه، أو لا يصل إلى من طلبه،
 * بلا خطأ وبلا تحذير.
 *
 * وللتطبيق قيمة إضافية: قيمة type هي ما يبني عليه التطبيق شكلَ الشاشة
 * وأيقونتها ووجهة النقر. عقدٌ بين خادم وتطبيقين لا يُترك لنصّ حر.
 *
 * ═══ الافتراضات معلَنة هنا لا في قاعدة البيانات ═══
 *
 * notification_preferences مفتاحه (user_id, notification_type)، فالمستخدم
 * الذي لم يفتح صفحة الإعدادات قط لا يملك صفاً — وهم الأغلبية الساحقة.
 * لو كان «لا صفّ» يعني «لا إشعار» لصمت النظام عن الجميع، ولو كان يعني
 * «كل القنوات» لأرسل بريداً ورسائل نصية لم يطلبها أحد.
 *
 * فالافتراض يُقرأ من هنا، وصفُّ التفضيلات يُكتب فقط حين يغيّر المستخدم
 * شيئاً فعلاً.
 */
enum NotificationType: string
{
    case NewMessage = 'new_message';
    case NewQuestion = 'new_question';
    case QuestionAnswered = 'question_answered';
    case ListingApproved = 'listing_approved';
    case ListingRejected = 'listing_rejected';

    public function label(): string
    {
        return match ($this) {
            self::NewMessage => 'رسالة جديدة',
            self::NewQuestion => 'سؤال على إعلانك',
            self::QuestionAnswered => 'جواب على سؤالك',
            self::ListingApproved => 'اعتُمد إعلانك',
            self::ListingRejected => 'رُفض إعلانك',
        };
    }

    /**
     * إشعار لا يملك المستخدم إسكاته.
     *
     * قرارات الإشراف وحدها. السبب أن الإعلان المرفوض يختفي من السوق،
     * والمعلن الذي أسكت الإشعارات لن يعرف لا أنه اختفى ولا لماذا —
     * فيعيد نشره بنفس السبب، ويُرفض ثانيةً. الإسكات هنا يضرّ صاحبه
     * ويضاعف عمل الإشراف معاً.
     *
     * والإجبار على القناة داخل التطبيق وحدها: الصفّ في القائمة سجلّ
     * يُقرأ عند الحاجة، لا مقاطعةٌ في الجيب. أما الدفع فيبقى اختياره
     * بيد صاحبه حتى في هذه الأنواع.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::ListingApproved, self::ListingRejected => true,
            default => false,
        };
    }

    public function defaultFor(Channel $channel): bool
    {
        return match ($channel) {
            Channel::InApp => true,
            Channel::Push => true,

            // منفَّذتان ومطفأتان بقصد: رسالة نصية تكلّف فعلياً، وبريدٌ
            // لم يطلبه أحد يُقرأ إزعاجاً. الافتراض هنا اختيارُ صمتٍ لا
            // عجزَ تنفيذٍ — من يريدهما يفتح صفحة التفضيلات ويُشغّلهما.
            Channel::Sms, Channel::Email => false,
        };
    }

    /**
     * هل تُطوى إشعارات هذا النوع على نفس الكائن؟
     *
     * محادثة فيها أربعون رسالة غير مقروءة يجب أن تكون سطراً واحداً في
     * القائمة لا أربعين. أما «اعتُمد إعلانك» فحدث يقع مرة، وطيّه لا
     * يوفّر شيئاً ويخاطر بابتلاع إشعار عن إعلان آخر.
     *
     * المفتاح نفسه يبنيه المُستدعي — انظر Notifier::send.
     */
    public function collapses(): bool
    {
        return $this === self::NewMessage;
    }
}

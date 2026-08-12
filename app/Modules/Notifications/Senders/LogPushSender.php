<?php

namespace App\Modules\Notifications\Senders;

use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\DTO\PushMessage;
use Illuminate\Support\Facades\Log;

/**
 * سائق التطوير — يكتب ولا يرسل.
 *
 * نظيره في وحدة الهوية DisplaySender. والفرق أن رمز التوثيق يُعرض على
 * الشاشة لأن المستخدم يحتاجه ليكمل، أما الإشعار فلا أحد ينتظره — فالسجلّ
 * موضعه الصحيح.
 *
 * ولا يرمي في الإنتاج خلافاً لـDisplaySender: رمز توثيق يُعرَض على
 * الشاشة في الإنتاج ثغرةٌ أمنية، أما إشعارٌ لم يُدفع فميزةٌ ناقصة. الأولى
 * تستحق الإيقاف والثانية تستحق سطراً في السجلّ.
 *
 * الرمز يُقصّ في السجلّ: رمز الدفع الكامل يكفي لإرسال إشعار إلى جهاز
 * بعينه، ولا سبب لبقائه في ملفات نصّية تُنسخ وتُشارَك.
 */
final class LogPushSender implements PushSender
{
    public function send(array $tokens, PushMessage $message): array
    {
        Log::info('[push] '.$message->title, [
            'notification_id' => $message->notificationId,
            'body' => $message->body,
            'data' => $message->data,
            'devices' => count($tokens),
            'tokens' => array_map(
                static fn (string $token): string => mb_substr($token, 0, 8).'…',
                $tokens
            ),
        ]);

        return [];
    }
}

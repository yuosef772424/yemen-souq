<?php

namespace App\Modules\Sms\Transports;

use App\Modules\Sms\Contracts\WhatsAppTransport;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * لا يُرسل — يكتب في السجلّ. للتطوير والاختبار.
 *
 * ويرمي في الإنتاج، بنفس حجّة `DisplaySender`: سائقٌ صامت يجعل خادماً
 * لا يوصّل رسالةً واحدة يبدو عاملاً تماماً — وهو أسوأ من عطلٍ ظاهر.
 *
 * والرمز **لا يُكتب في السجلّ**. الرقم مقصوصٌ كذلك: ملفات السجلّ تُقرأ
 * وتُصدَّر وتُرسَل في تذاكر الدعم، ورمزُ توثيقٍ فيها يوثّق حساباً.
 */
final class LogWhatsAppTransport implements WhatsAppTransport
{
    public function send(string $to, string $text, ?string $code = null): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'سائق واتساب `log` لا يعمل في الإنتاج. اضبط WHATSAPP_DRIVER=cloud أو http.'
            );
        }

        Log::info('واتساب (بيئة تطوير)', [
            'to' => substr($to, 0, -4).'****',
            'length' => mb_strlen($text),
        ]);
    }
}

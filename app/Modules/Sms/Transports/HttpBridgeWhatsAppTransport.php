<?php

namespace App\Modules\Sms\Transports;

use App\Modules\Sms\Contracts\WhatsAppTransport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * جسرٌ تستضيفه أنت — عقدٌ من حقلين.
 *
 * ═══ لماذا يوجد ═══
 *
 * واجهة Meta تحتاج حساب أعمال موثَّقاً وقالباً معتمَداً، وهو طريق أسابيع.
 * وهذا السائق يدفع `{to, text}` إلى أي عنوان تعطيه ويقبل أي رد 2xx —
 * فيصله ما تشغّله أنت: جسرٌ رسمي، أو خادمٌ على هاتف، أو صفٌّ ثانٍ.
 *
 * ═══ وتحذيرٌ لا يُخفّف ═══
 *
 * المكتبات غير الرسمية (Baileys، whatsapp-web.js) تعمل اليوم بلا موافقة
 * — وتخالف شروط واتساب. والرقم يُحظر بلا إنذار ولا استئناف، وحظرُ رقمٍ
 * يوثّق به آلافُ المستخدمين أثقل من تأخّر التوثيق أسابيع.
 *
 * هذا السائق يجعل ذلك **خيارك بمعرفتك**: العقد مفتوح، والمنفّذ خارج هذا
 * المستودع، والمسؤولية عمّا يُوصَل به ليست على الكود.
 *
 * ═══ ولماذا لا يُرسَل الرمز في حقل مستقل ═══
 *
 * الجسر يوصّل نصّاً. وحقلٌ اسمه `code` يغري بتسجيله في سجلّ الجسر
 * منفصلاً عن النصّ — فيصير الرمز في موضعين على جهازٍ لا نتحكّم فيه.
 */
final class HttpBridgeWhatsAppTransport implements WhatsAppTransport
{
    public function send(string $to, string $text, ?string $code = null): void
    {
        $url = (string) config('sms.whatsapp.http.url', '');
        $token = (string) config('sms.whatsapp.http.token', '');

        if ($url === '') {
            throw new RuntimeException('إعداد واتساب ناقص: sms.whatsapp.http.url');
        }

        // https في الإنتاج شرط لا توصية: النصّ يحمل رمز توثيق، وعلى
        // http يقرؤه كل وسيط في الطريق
        if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('جسر واتساب على http في الإنتاج — النصّ يحمل رمز توثيق.');
        }

        $request = Http::acceptJson()->timeout(15)->retry(2, 300, throw: false);

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, ['to' => $to, 'text' => $text]);

        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            'جسر واتساب ردّ '.$response->status().': '.mb_substr($response->body(), 0, 120)
        );
    }
}

<?php

namespace App\Modules\Sms\Transports;

use App\Modules\Sms\Contracts\WhatsAppTransport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * واجهة Meta الرسمية (WhatsApp Cloud API).
 *
 * ═══ قالب معتمَد لا نصّ حرّ ═══
 *
 * واتساب يرفض بدء محادثة بنصّ حرّ. الرسالة تُرسَل بقالب صنّفته Meta
 * `AUTHENTICATION` واعتمدته مسبقاً، والرمز يُمرَّر **معامِلاً** له.
 *
 * وهذا يعني أن نصّ `sms.templates.phone_verification` لا يُستعمل هنا:
 * الصياغة تعيش عند Meta لا عندنا. والخلط بينهما هو أكثر ما يُربك من
 * يجرّب أول مرة — فيغيّر النصّ في `config` ويرى الرسالة كما هي.
 *
 * ═══ ومعامِل زرّ النسخ ═══
 *
 * قوالب المصادقة عند Meta تُرسَل مع زرّ «نسخ الرمز»، والزرّ يحتاج
 * المعامِل مكرَّراً في `button` — بلا ذلك يُقبل الطلب ويصل بلا زرّ.
 *
 * ═══ الفشل يرمي برسالة Meta لا برسالة عامة ═══
 *
 * أكثر الأخطاء شيوعاً محدَّدة تماماً: `131047` نافذة مغلقة،
 * `132001` قالب غير موجود، `131026` الرقم لا يملك واتساب. ورسالةٌ عامة
 * («فشل الإرسال») تجعل الثلاثة عطلاً واحداً — والثالث وحده هو ما يستحق
 * تراجعاً إلى قناة أخرى.
 */
final class CloudApiWhatsAppTransport implements WhatsAppTransport
{
    public function send(string $to, string $text, ?string $code = null): void
    {
        $config = (array) config('sms.whatsapp.cloud');

        foreach (['phone_number_id', 'token'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("إعداد واتساب ناقص: sms.whatsapp.cloud.{$key}");
            }
        }

        $version = $config['version'] ?? 'v21.0';
        $url = "https://graph.facebook.com/{$version}/{$config['phone_number_id']}/messages";

        // الرقم بلا علامة الزائد: Meta تقبل الرقمين وتُرجع E.164 بلا علامة،
        // والتوحيد هنا يجعل مطابقة السجلّات بالرد ممكنة
        $recipient = ltrim($to, '+');

        $response = Http::withToken($config['token'])
            ->acceptJson()
            ->timeout(15)
            // محاولتان فقط: الثالثة تخصّ الطابور لا هذا النداء — وإعادةٌ
            // هنا تطيل قفل السحبة بلا أن تغيّر سبب الفشل
            ->retry(2, 300, throw: false)
            ->post($url, $this->payload($recipient, $code ?? $text, $config));

        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($this->reason($response->json(), $response->status()));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function payload(string $recipient, string $code, array $config): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $config['template'] ?? 'verification_code',
                'language' => ['code' => $config['language'] ?? 'ar'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                    [
                        // زرّ «نسخ الرمز» — يحتاج المعامِل مكرَّراً
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body, int $status): string
    {
        $error = $body['error'] ?? [];

        $code = $error['code'] ?? null;
        $message = $error['message'] ?? 'بلا تفصيل';

        $hint = match ((int) $code) {
            131026 => ' — الرقم لا يملك واتساب',
            131047 => ' — نافذة المحادثة مغلقة، والقالب مطلوب',
            132001 => ' — القالب غير موجود أو غير معتمَد',
            190 => ' — رمز الوصول منتهٍ',
            default => '',
        };

        return "واتساب رفض الطلب ({$status}/{$code}): {$message}{$hint}";
    }
}

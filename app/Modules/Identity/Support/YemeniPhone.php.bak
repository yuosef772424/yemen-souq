<?php

namespace App\Modules\Identity\Support;

/**
 * تطبيع أرقام الهواتف اليمنية إلى صيغة E.164 واحدة.
 *
 * لماذا هذا ضروري لا تجميلي: عمود phone فريد (unique). ومن يسجّل
 * بـ0771234567 ثم يسجّل بـ+967771234567 يُنشئ حسابين لنفس الرقم،
 * فينكسر التوثيق ويصبح الرقم غير صالح كهوية. التطبيع عند الحدّ
 * الخارجي هو ما يجعل القيد الفريد ذا معنى.
 *
 * المدخلات الواقعية: 0771234567 · 771234567 · +967771234567 ·
 * 00967771234567 · وبينها مسافات وشرطات وأرقام عربية.
 */
final class YemeniPhone
{
    /** بادئات الجوال العاملة في اليمن */
    private const MOBILE_PREFIXES = ['70', '71', '73', '77', '78'];

    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        // الأرقام العربية أولاً، ثم كل ما ليس رقماً
        $digits = strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        // 00967… ثم 967… ثم 0… — بهذا الترتيب، وإلا أكل الشرط الأخير الأول
        if (str_starts_with($digits, '00967')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '967')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 9 || ! in_array(substr($digits, 0, 2), self::MOBILE_PREFIXES, true)) {
            return null;
        }

        return '+967' . $digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** للعرض: +967771234567 → 0771234567 */
    public static function forDisplay(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+967')) {
            return $e164;
        }

        return '0' . substr($e164, 4);
    }
}
<?php

namespace App\Modules\Identity\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * مستخدم بديل حتى تُضاف المصادقة.
 *
 * الغرض: أن يعمل نشر الإعلان في العرض التجريبي بلا تسجيل دخول.
 * حين تُثبَّت Breeze، تُرجع Auth::user() تلقائياً ولا يُستخدم البديل —
 * فلا يحتاج أي كود آخر تعديلاً.
 */
final class DemoUser
{
    public const EMAIL = 'demo@example.test';

    public static function current(): User
    {
        if (Auth::check()) {
            return Auth::user();
        }

        return User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'معلن تجريبي',
                'password' => Hash::make(str()->random(32)),
                'email_verified_at' => now(),
            ]
        );
    }
}

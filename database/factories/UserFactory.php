<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * كل مستخدم يملك صفّ عرض — كما في `RegisterController` بالضبط.
     *
     * بلا هذا كان `users: 14` مقابل `user_profiles: 3`: أحد عشر مستخدماً
     * أنشأتهم البذور والاختبارات بلا صفّ. و`displayName()` تتراجع إلى
     * `users.name` فلا ينكسر شيء ظاهرياً — لكن كل ما يُبنى على الملف
     * الشخصي (النبذة، الموقع المفضّل، الحساب التجاري) يعمل على ثلاثة من
     * أربعة عشر. عطلٌ صامت من عائلة `price_in_base` الفارغ.
     *
     * ولماذا في الـfactory لا في خطّاف `User::created`: الخطّاف يجعل كل
     * إنشاء مستخدم يكتب صفّاً ثانياً بلا أن يطلبه أحد — بما فيه المسارات
     * التي تكتبه بنفسها (`RegisterController` داخل معاملته)، فيتصادمان
     * على المفتاح.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            UserProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['display_name' => $user->name],
            );
        });
    }

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

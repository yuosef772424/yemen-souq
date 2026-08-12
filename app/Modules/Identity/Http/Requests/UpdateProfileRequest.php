<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Services\AvatarService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تعديل بيانات العرض — لا الهوية.
 *
 * ما ليس في هذه القائمة مقصود بقدر ما فيها: لا `phone` ولا `password`
 * ولا `status` ولا `role`. كلٌّ منها له مساره وشرطه — تغيير الرقم يُبطل
 * التوثيق، وتغيير الكلمة يحتاج الكلمة الحالية، والحالة والدور قرارا
 * إشرافٍ لا حقلا نموذج. حقلٌ واحد منها هنا يعني أن أي معلن يرقّي نفسه
 * إلى مدير بطلب POST مُعدَّل.
 */
class UpdateProfileRequest extends FormRequest
{
    /** حزمة أخطاء باسم النموذج — وإلا ظهر خطأ كلمة المرور فوق حقل الاسم */
    protected $errorBag = 'profile';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],

            /*
             * البريد يبقى قابلاً للتعديل رغم أن الهوية هي الرقم: هو
             * المعرّف الثاني للدخول، ومن كتبه خطأً عند التسجيل لا يملك
             * طريقاً آخر لتصحيحه.
             */
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],

            'display_name' => ['required', 'string', 'min:3', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'preferred_currency' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],

            /*
             * الصورة في نفس نموذج البيانات لا في مسارٍ مستقل: من يفتح
             * الإعدادات ليغيّر اسمه يغيّر صورته في نفس الحفظة.
             *
             * وقاعدة `image` تفحص المحتوى لا الامتداد — فملفٌ أُعيدت
             * تسميته يُرفض. و`dimensions` تمنع صورةً بحجم بكسل واحد
             * تُقصّ إلى مربّع فارغ.
             */
            'avatar' => [
                'nullable', 'file', 'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.AvatarService::MAX_KB,
                'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000',
            ],
            'remove_avatar' => ['nullable', 'boolean'],

            'is_business' => ['nullable', 'boolean'],
            // اسم النشاط مطلوب متى أُعلن الحساب تجارياً: حسابٌ تجاري بلا
            // اسم يُعرض كفرد، فلا يفيد صاحبه ولا يفيد من يقرأ الإعلان
            // `required_if:…,true` لا `,1`: الحقل يصل منطقياً من
            // prepareForValidation، ولارافل يحوّل «true» النصّية إلى
            // منطقية عند المقارنة ولا يحوّل «1» — فالشرط بـ1 لا يتحقق
            // أبداً، ويمرّ حسابٌ تجاري بلا اسم
            'business_name' => ['nullable', 'required_if:is_business,true', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'هذا البريد مسجَّل في حساب آخر.',
            'display_name.required' => 'الاسم المعروض لا يمكن أن يكون فارغاً.',
            'business_name.required_if' => 'اكتب اسم النشاط التجاري.',
            'avatar.image' => 'الملف المرفوع ليس صورة صالحة.',
            'avatar.mimes' => 'الصيغ المقبولة: JPG أو PNG أو WebP.',
            'avatar.max' => 'حجم الصورة يجب أن يكون أقل من 4 ميجابايت.',
            'avatar.dimensions' => 'الصورة صغيرة جداً أو كبيرة جداً.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'display_name' => 'الاسم المعروض',
            'bio' => 'نبذة',
            'location_id' => 'الموقع',
            'preferred_currency' => 'العملة المفضّلة',
            'business_name' => 'اسم النشاط',
            'avatar' => 'الصورة',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_business' => $this->boolean('is_business'),
            'remove_avatar' => $this->boolean('remove_avatar'),
        ]);
    }
}

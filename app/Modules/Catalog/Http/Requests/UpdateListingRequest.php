<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Services\ListingImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قواعد التعديل.
 *
 * لماذا صنف منفصل لا إعادة استخدام StoreListingRequest: القواعد
 * متشابهة اليوم لكنها تتباعد بالضرورة — النشر سيحتاج قيوداً على
 * عدد الإعلانات اليومي، والتعديل سيحتاج منع تغيير التصنيف بعد
 * تلقّي عروض. توريث القواعد الآن يعني تفكيكه لاحقاً بشروط if.
 *
 * والأهم هنا ليس ما في القائمة بل ما ليس فيها: لا status، لا user_id،
 * لا is_featured، لا expires_at، لا published_at. حقل واحد من هذه في
 * القائمة يعني أن أي معلن يميّز إعلانه أو يمدّده بطلب POST مُعدَّل.
 */
class UpdateListingRequest extends FormRequest
{
    /** الصلاحية تُفحص في المتحكّم بـGate — في مكان واحد لكل الأفعال السبعة */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:6', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],

            'price_type' => ['required', Rule::in(['fixed', 'negotiable', 'on_request', 'free'])],
            'price_amount' => ['nullable', 'required_if:price_type,fixed,negotiable', 'numeric', 'min:0', 'max:99999999999'],
            'currency_code' => ['nullable', 'required_with:price_amount', 'exists:currencies,code'],

            'condition_type' => ['nullable', Rule::in(['new', 'like_new', 'used', 'for_parts'])],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{9,20}$/'],
            'hide_phone' => ['nullable', 'boolean'],

            'images' => ['nullable', 'array', 'max:'.ListingImageService::MAX_PER_LISTING],
            'images.*' => [
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:8192',
                'dimensions:min_width=120,min_height=120,max_width=8000,max_height=8000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'اكتب عنواناً للإعلان.',
            'title.min' => 'العنوان قصير جداً — اجعله وصفياً أكثر.',
            'category_id.required' => 'اختر تصنيفاً.',
            'location_id.required' => 'اختر الموقع.',
            'price_amount.required_if' => 'أدخل السعر، أو اختر «السعر عند الطلب».',
            'currency_code.required_with' => 'اختر عملة السعر.',
            'contact_phone.required' => 'أدخل رقم تواصل.',
            'contact_phone.regex' => 'رقم التواصل غير صالح.',
            'images.max' => 'الحد الأقصى '.ListingImageService::MAX_PER_LISTING.' صور.',
            'images.*.image' => 'أحد الملفات ليس صورة صالحة.',
            'images.*.mimes' => 'الصيغ المقبولة: JPG أو PNG أو WebP. صور HEIC من الآيفون تحتاج تحويلاً أولاً.',
            'images.*.max' => 'حجم كل صورة يجب أن يكون أقل من 8 ميجابايت.',
            'images.*.dimensions' => 'أبعاد إحدى الصور صغيرة جداً أو كبيرة جداً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('price_type'), ['free', 'on_request'], true)) {
            $this->merge(['price_amount' => null, 'currency_code' => null]);
        }

        $this->merge(['hide_phone' => $this->boolean('hide_phone')]);

        if (is_string($phone = $this->input('contact_phone'))) {
            $this->merge(['contact_phone' => preg_replace('/[\s\-]/', '', $phone)]);
        }
    }
}
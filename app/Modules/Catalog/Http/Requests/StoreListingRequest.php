<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Services\ListingImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingRequest extends FormRequest
{
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
            // السعر مطلوب فقط إن لم يكن مجانياً أو عند الطلب
            'price_amount' => ['nullable', 'required_if:price_type,fixed,negotiable', 'numeric', 'min:0', 'max:99999999999'],
            'currency_code' => ['nullable', 'required_with:price_amount', 'exists:currencies,code'],

            'condition_type' => ['nullable', Rule::in(['new', 'like_new', 'used', 'for_parts'])],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{9,20}$/'],

            // الصور اختيارية — إعلان بلا صورة أفضل من إعلان لم يُنشر
            'images' => ['nullable', 'array', 'max:' . ListingImageService::MAX_PER_LISTING],
            'images.*' => [
                'file',
                // قاعدة image تفحص المحتوى لا الامتداد، فملف PDF مُعاد تسميته يُرفض
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
            'images.max' => 'الحد الأقصى ' . ListingImageService::MAX_PER_LISTING . ' صور.',
            'images.*.image' => 'أحد الملفات ليس صورة صالحة.',
            'images.*.mimes' => 'الصيغ المقبولة: JPG أو PNG أو WebP. صور HEIC من الآيفون تحتاج تحويلاً أولاً.',
            'images.*.max' => 'حجم كل صورة يجب أن يكون أقل من 8 ميجابايت.',
            'images.*.dimensions' => 'أبعاد إحدى الصور صغيرة جداً أو كبيرة جداً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // «مجاناً» و«عند الطلب» لا يحملان سعراً — ننظّف الحقلين قبل التحقق
        if (in_array($this->input('price_type'), ['free', 'on_request'], true)) {
            $this->merge(['price_amount' => null, 'currency_code' => null]);
        }

        if (is_string($phone = $this->input('contact_phone'))) {
            $this->merge(['contact_phone' => preg_replace('/[\s\-]/', '', $phone)]);
        }
    }
}

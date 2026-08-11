<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Services\ReportSubmitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ReportSubmitter::REASONS)],

            /**
             * التفاصيل مطلوبة مع «أخرى» وحدها.
             *
             * إجبار كل مُبلِّغ على الكتابة يقلّل البلاغات الصحيحة: من يرى
             * سلعة ممنوعة يريد ضغطة لا مقالاً. و«أخرى» بلا شرح بلاغ لا
             * يمكن الفصل فيه — فيبقى في الطابور حتى يُصرَف بلا قراءة.
             */
            'details' => ['nullable', 'string', 'max:500', 'required_if:reason,other', 'min:10'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'السبب', 'details' => 'التفاصيل'];
    }

    public function messages(): array
    {
        return [
            'details.required_if' => 'اشرح المخالفة عند اختيار «أخرى» — بلا شرح لا يمكن الفصل في البلاغ.',
        ];
    }
}

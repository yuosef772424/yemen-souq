<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\StoreListingReportRequest;
use App\Modules\Engagement\Services\ReportSubmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

class ListingReportController
{
    public function __construct(
        private readonly ReportSubmitter $reports,
    ) {}

    /**
     * الحدّ: خمسة بلاغات في الساعة للحساب الواحد.
     *
     * البديل المرفوض: بلا حدّ، والاعتماد على «بلاغ واحد لكل إعلان». ذلك
     * الشرط يمنع التكرار على *إعلان واحد* ولا يمنع حساباً واحداً من
     * الإبلاغ على خمسين إعلاناً لمنافس في دقيقتين — وهو الهجوم الحقيقي
     * لأنه يُغرق الطابور فيتوقف الإشراف عن العمل.
     *
     * والرسالة تقول السبب صريحاً. «حدث خطأ» يجعل المُبلِّغ الصادق يعيد
     * المحاولة ويظن الموقع معطوباً.
     */
    public function store(StoreListingReportRequest $request, Listing $listing): RedirectResponse
    {
        $key = 'report:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'بلاغات كثيرة في وقت قصير. أعد المحاولة بعد ساعة.');
        }

        $report = $this->reports->submit(
            $request->user(),
            $listing,
            $request->string('reason')->value(),
            $request->input('details'),
        );

        if ($report === null) {
            return back()->with('error', 'لديك بلاغ مفتوح على هذا الإعلان، أو أنه إعلانك.');
        }

        RateLimiter::hit($key, 3600);

        return back()->with('status', 'وصل بلاغك وسيُراجَع. شكراً — البلاغات هي ما يجعل السوق نظيفاً.');
    }
}

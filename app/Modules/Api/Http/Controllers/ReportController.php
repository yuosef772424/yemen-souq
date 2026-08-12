<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\StoreListingReportRequest;
use App\Modules\Engagement\Services\ReportSubmitter;
use Illuminate\Http\JsonResponse;

/**
 * الإبلاغ عن إعلان.
 *
 * ═══ null من الخدمة يعني «مرفوض» لا «فشل» ═══
 *
 * `ReportSubmitter::submit` تُرجع null في حالتين: المالك يبلّغ على
 * نفسه، أو له بلاغ مفتوح على نفس الإعلان. وكلتاهما قرار مجال لا عطل —
 * فترجمتهما إلى 500 تجعل المستخدم يعيد المحاولة على ما لن ينجح.
 *
 * و409 لا 422: الطلب صحيح تماماً، والحالة هي ما يمنعه.
 *
 * ═══ الأسباب تُعرَض من الخدمة ═══
 *
 * `ReportSubmitter::REASONS` هو مصدر الحقيقة، ويطابق enum المخطط.
 * وقائمةٌ ثابتة في التطبيق تتقادم عند أول سبب يُضاف — فتُرسَل مع
 * النموذج لا تُكتب فيه.
 */
class ReportController
{
    public function __construct(
        private readonly ReportSubmitter $reports,
    ) {}

    /** أسباب البلاغ — يبني منها التطبيق قائمته */
    public function reasons(): JsonResponse
    {
        return response()->json(['data' => ReportSubmitter::REASONS]);
    }

    public function store(StoreListingReportRequest $request, Listing $listing): JsonResponse
    {
        $report = $this->reports->submit(
            $request->user(),
            $listing,
            (string) $request->validated('reason'),
            $request->validated('details'),
        );

        if ($report === null) {
            return response()->json([
                'message' => 'لديك بلاغ مفتوح على هذا الإعلان، أو أنه إعلانك.',
                'code' => 'report_not_allowed',
            ], 409);
        }

        return response()->json([
            'message' => 'وصل بلاغك. سيراجعه الفريق.',
            'data' => [
                'id' => $report->id,
                'reason' => $report->reason,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}

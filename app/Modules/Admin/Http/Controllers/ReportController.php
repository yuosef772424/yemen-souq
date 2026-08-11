<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\ModerationReasonRequest;
use App\Modules\Admin\Services\ReportResolver;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController
{
    public function __construct(
        private readonly ReportResolver $resolver,
    ) {}

    /**
     * المفتوح أولاً، والأقدم قبل الأحدث داخله.
     *
     * والمنفَّذ والمصروف يبقيان معروضين في تبويبهما: طابور يخفي ما
     * فُصِل فيه يجعل مراجعة قرار سابق مستحيلة من الواجهة.
     */
    public function index(Request $request): View
    {
        $tab = $request->string('tab')->value() === 'closed' ? 'closed' : 'open';

        $query = ListingReport::query()->with(['listing', 'reporter', 'reviewer']);

        $tab === 'open'
            ? $query->open()->orderBy('created_at')
            : $query->whereIn('status', ['actioned', 'dismissed'])->orderByDesc('reviewed_at');

        return view('admin::reports.index', [
            'tab' => $tab,
            'reports' => $query->paginate(20)->withQueryString(),
            'openCount' => ListingReport::query()->open()->count(),
            'closedCount' => ListingReport::query()->whereIn('status', ['actioned', 'dismissed'])->count(),
        ]);
    }

    public function dismiss(Request $request, ListingReport $report): RedirectResponse
    {
        $this->resolver->dismiss($report, $request->user(), $request->input('reason'));

        return back()->with('status', 'صُرِف البلاغ.');
    }

    public function action(ModerationReasonRequest $request, ListingReport $report): RedirectResponse
    {
        $this->resolver->action($report, $request->user(), $request->reason());

        return back()->with('status', 'نُفِّذ البلاغ ورُفض الإعلان.');
    }
}

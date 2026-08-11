<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\ModerationReasonRequest;
use App\Modules\Admin\Services\QuestionModerator;
use App\Modules\Engagement\Models\ListingQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuestionModerationController
{
    private const TABS = ['recent', 'unanswered', 'hidden'];

    public function __construct(
        private readonly QuestionModerator $moderator,
    ) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->string('tab')->value(), self::TABS, true)
            ? $request->string('tab')->value()
            : 'recent';

        $query = ListingQuestion::query()->with(['listing', 'asker']);

        match ($tab) {
            'unanswered' => $query->whereNull('answered_at')->whereNull('hidden_at'),
            'hidden' => $query->whereNotNull('hidden_at'),
            default => $query,
        };

        return view('admin::questions.index', [
            'tab' => $tab,
            'questions' => $query->orderByDesc('created_at')->paginate(20)->withQueryString(),
        ]);
    }

    public function hide(ModerationReasonRequest $request, ListingQuestion $question): RedirectResponse
    {
        $this->moderator->hide($question, $request->user(), $request->reason());

        return back()->with('status', 'أُخفي السؤال.');
    }

    public function unhide(Request $request, ListingQuestion $question): RedirectResponse
    {
        $this->moderator->unhide($question, $request->user());

        return back()->with('status', 'أُظهر السؤال.');
    }
}

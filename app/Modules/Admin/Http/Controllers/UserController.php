<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Models\User;
use App\Modules\Admin\Enums\UserRole;
use App\Modules\Admin\Services\UserAdministrator;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * إدارة الحسابات — للمدير وحده (EnsureIsAdmin على المجموعة).
 *
 * البحث بالهاتف أو البريد أو الاسم. لماذا الهاتف أولاً: في اليمن الرقم
 * هو ما يعرفه الناس عن أنفسهم، وشكوى تصل عن حساب تصل بالرقم لا بالبريد
 * — نفس حجة LoginController.
 */
class UserController
{
    public function __construct(
        private readonly UserAdministrator $admin,
        private readonly ArabicNormalizer $normalizer,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->string('q')->value());
        $filter = $request->string('filter')->value();

        $users = User::query()
            ->when($q !== '', function ($query) use ($q): void {
                $needle = $this->normalizer->normalize($q);
                $query->where(function ($inner) use ($q, $needle): void {
                    $inner->where('phone', 'like', '%' . preg_replace('/\D+/', '', $q) . '%')
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$needle}%");
                });
            })
            ->when($filter === 'staff', fn ($query) => $query->whereIn('role', ['moderator', 'admin']))
            ->when($filter === 'blocked', fn ($query) => $query->whereIn('status', ['suspended', 'banned']))
            // استعلام فرعي لا withCount: لا نضيف علاقة listings() إلى
            // App\Models\User، فذلك يربط ملف لارافل الافتراضي بوحدة
            // الكتالوج ويجعل حذف الوحدة يكسر الموديل الأساسي
            ->addSelect(['listings_count' => Listing::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('listings.user_id', 'users.id')
                ->whereNull('listings.deleted_at'), ])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin::users.index', [
            'users' => $users,
            'q' => $q,
            'filter' => $filter,
            'roles' => UserRole::assignable(),
        ]);
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'banned'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $outcome = $this->admin->setStatus($user, $request->user(), $data['status'], $data['reason'] ?? null);

        return back()->with($outcome->succeeded() ? 'status' : 'error', $outcome->message());
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $outcome = $this->admin->setRole(
            $user,
            $request->user(),
            UserRole::from($data['role']),
            $data['reason'] ?? null,
        );

        return back()->with($outcome->succeeded() ? 'status' : 'error', $outcome->message());
    }
}

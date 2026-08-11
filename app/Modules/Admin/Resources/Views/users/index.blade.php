@extends('admin::layout')

@section('title', 'الحسابات')

@section('content')

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <h1 class="text-xl font-bold">الحسابات</h1>

    <form method="GET" action="{{ route('admin.users') }}" class="flex items-center gap-2">
        <label class="sr-only" for="q">بحث</label>
        <input type="search" id="q" name="q" value="{{ $q }}" placeholder="هاتف أو بريد أو اسم"
               class="rounded-lg border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">
        <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-semibold text-white">بحث</button>
    </form>
</div>

<nav class="mt-5 flex gap-2 border-b border-line pb-px" aria-label="تصفية">
    @foreach (['' => 'الكل', 'staff' => 'الفريق', 'blocked' => 'موقوفة ومحظورة'] as $key => $label)
        <a href="{{ route('admin.users', array_filter(['filter' => $key, 'q' => $q])) }}"
           @class([
               'rounded-t-lg px-3 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => (string) $filter === $key,
               'text-muted hover:text-ink' => (string) $filter !== $key,
           ])>{{ $label }}</a>
    @endforeach
</nav>

@if ($users->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا حسابات مطابقة.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($users as $user)
            @php $role = \App\Modules\Admin\Enums\UserRole::of($user); @endphp

            <li class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold">
                        {{ $user->name }}
                        <span class="text-xs font-normal text-muted">#{{ $user->id }}</span>
                    </h2>

                    <div class="flex items-center gap-1.5 text-[11px]">
                        <span @class([
                            'rounded-md px-2 py-0.5 font-semibold',
                            'bg-ink text-white' => $role->value === 'admin',
                            'bg-brand/10 text-brand-dark' => $role->value === 'moderator',
                            'bg-tint text-muted' => $role->value === 'member',
                        ])>{{ $role->label() }}</span>

                        <span @class([
                            'rounded-md px-2 py-0.5 font-semibold',
                            'bg-green-100 text-green-900' => $user->status === 'active',
                            'bg-amber-100 text-amber-900' => $user->status === 'suspended',
                            'bg-red-100 text-red-900' => $user->status === 'banned',
                        ])>{{ ['active' => 'نشط', 'suspended' => 'موقوف', 'banned' => 'محظور'][$user->status] ?? $user->status }}</span>
                    </div>
                </div>

                <p class="mt-1.5 text-xs text-muted">
                    {{ $user->phone ?? 'بلا هاتف' }} · {{ $user->email }}
                    · {{ $user->listings_count }} إعلاناً
                    · سجّل {{ $user->created_at?->diffForHumans() }}
                    @if ($user->last_login_at) · آخر دخول {{ \Illuminate\Support\Carbon::parse($user->last_login_at)->diffForHumans() }} @endif
                </p>

                @if ($user->id === auth()->id())
                    <p class="mt-3 border-t border-line pt-3 text-xs text-muted">هذا حسابك — لا إجراءات عليه من هنا.</p>
                @else
                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">

                        <form method="POST" action="{{ route('admin.users.role', $user) }}" class="flex items-center gap-1.5">
                            @csrf
                            <label class="sr-only" for="role-{{ $user->id }}">الدور</label>
                            <select id="role-{{ $user->id }}" name="role"
                                    class="rounded-lg border border-line px-2 py-2 text-xs focus:border-brand focus:outline-none">
                                @foreach ($roles as $option)
                                    <option value="{{ $option->value }}" @selected($option === $role)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                                تغيير الدور
                            </button>
                        </form>

                        @foreach (['active' => 'تنشيط', 'suspended' => 'إيقاف', 'banned' => 'حظر'] as $status => $label)
                            @continue($user->status === $status)
                            <form method="POST" action="{{ route('admin.users.status', $user) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <button type="submit"
                                        @class([
                                            'rounded-lg px-3 py-2 text-xs font-semibold transition',
                                            'border border-red-200 text-red-700 hover:bg-red-50' => $status === 'banned',
                                            'border border-line text-muted hover:text-ink' => $status !== 'banned',
                                        ])>{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $users->links() }}</div>
@endif

@endsection

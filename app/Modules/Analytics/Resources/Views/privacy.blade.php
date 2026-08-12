@extends('shared::layouts.app')

@section('title', 'الخصوصية والتتبّع')

@section('content')

@php
    // غياب الصفّ = الافتراض مسموح — نفس ما تقرؤه EventRecorder::allowed
    $analytics = old('allow_analytics', $preference?->allow_analytics ?? true);
    $personalization = old('allow_personalization', $preference?->allow_personalization ?? true);
@endphp

<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="text-xl font-bold">الخصوصية والتتبّع</h1>
        <p class="mt-1 text-sm text-muted">
            ما نقيسه من استعمالك، وما تستطيع إيقافه.
            <a href="{{ route('account.settings') }}" class="font-medium text-brand-dark underline">عودة إلى إعدادات الحساب</a>
        </p>
    </div>

    <div class="rounded-2xl border border-line bg-white p-5 text-sm">
        <h2 class="text-base font-semibold">ما لا نسجّله أصلاً</h2>
        <ul class="mt-3 space-y-1.5 text-muted">
            <li>— لا عنوان IP في سجلّ السلوك.</li>
            <li>— لا أسماء ولا أرقام هواتف ولا نصوص رسائل في حمولة الأحداث.</li>
            <li>— السجلّ الخام يُمحى بعد تسعين يوماً، ويبقى التجميع اليومي بلا هوية.</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('account.privacy.update') }}"
          class="space-y-4 rounded-2xl border border-line bg-white p-5">
        @csrf
        @method('PUT')

        <label class="flex gap-3">
            <input type="checkbox" name="allow_analytics" value="1" @checked($analytics)
                   class="mt-1 size-4 shrink-0 rounded border-line">
            <span class="text-sm">
                <span class="font-medium">قياس الاستعمال</span>
                <span class="mt-1 block text-muted">
                    تسجيل ما يُبحث عنه وما يُفتح — لتحسين البحث والفلاتر. إيقافه يمنع تسجيل أي حدث
                    من حسابك من لحظة الحفظ.
                </span>
            </span>
        </label>

        <label class="flex gap-3">
            <input type="checkbox" name="allow_personalization" value="1" @checked($personalization)
                   class="mt-1 size-4 shrink-0 rounded border-line">
            <span class="text-sm">
                <span class="font-medium">التخصيص حسب اهتمامي</span>
                <span class="mt-1 block text-muted">
                    بناء صورة عن التصنيفات التي تهمّك من سلوكك. إيقافه يمحو ما بُني عنك فوراً،
                    ويستثنيك من الحساب بعدها.
                </span>
            </span>
        </label>

        <p class="text-xs text-muted">
            الإيقاف يوقف التسجيل من الآن، ولا يمحو ما سُجِّل قبله — محوُه يكسر تجميعات يومية
            بُنيت فعلاً. والخام يُمحى بعد تسعين يوماً لكل الناس على أي حال.
        </p>

        <button type="submit"
                class="rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">
            حفظ
        </button>
    </form>

</div>

@endsection

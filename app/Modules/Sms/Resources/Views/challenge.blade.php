@extends('shared::layouts.app')

@section('title', 'التوثيق بإرسال رسالة')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">وثّق رقمك بإرسال رسالة</h1>
    <p class="mt-1 text-sm text-muted">
        بدل أن ننتظر وصول رمزٍ إليك، أرسل أنت هذا الرمز إلينا من جوالك —
        ويتوثّق رقمك بمجرد وصول الرسالة.
    </p>

    @if (! $number)
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            هذه الخدمة غير متاحة حاليًا، حاول لاحقًا.
        </div>
    @else
        {{-- استطلاعٌ خفيف كل أربع ثوانٍ لا اتصال دائم: الحدث نادر (توثيق
             واحد لكل مستخدم) ومهلته دقائق، فبولينغ بسيط يكفي بلا حاجة
             WebSocket. عند التوثيق تُعاد الصفحة نفسها فيتولّى المتحكّم
             (ChallengeController::show) التحويل ورسالة النجاح — لا منطق
             مكرَّر هنا. --}}
        <div x-data="{
                checking: false,
                poll() {
                    if (this.checking) return;
                    this.checking = true;
                    fetch('{{ route('sms.challenge.status') }}', { headers: { Accept: 'application/json' } })
                        .then((response) => response.json())
                        .then((data) => { if (data.verified) location.href = '{{ route('sms.challenge') }}'; })
                        .finally(() => { this.checking = false; });
                },
             }"
             x-init="poll(); setInterval(() => poll(), 4000)"
        >
            <div class="mt-6 rounded-xl border border-line bg-white p-5">
                <p class="text-sm text-muted">أرسل هذا النصّ كما هو:</p>
                <p dir="ltr" class="mt-2 text-center text-2xl font-bold tracking-[0.2em] tabular-nums">{{ $display }}</p>

                <p class="mt-4 text-sm text-muted">إلى الرقم:</p>
                <p dir="ltr" class="mt-1 text-center text-lg font-semibold tabular-nums">{{ $number }}</p>

                <p class="mt-4 text-xs text-muted">
                    تنتهي صلاحيته بعد {{ $minutes }} دقيقة. هذه الصفحة تتحقّق من وصول رسالتك
                    تلقائياً وتنقلك بمجرد التوثيق — لا حاجة لتحديثها بنفسك.
                </p>
            </div>

            @if ($smsLink)
                {{-- الرابط يفتح مؤلّف الرسائل بالرقم والنصّ جاهزين: النسخ
                     اليدوي هو أكثر ما يُخطئ فيه من يكتب بالإبهام --}}
                <a href="{{ $smsLink }}"
                   class="mt-4 block w-full rounded-xl bg-brand px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-brand-dark">
                    افتح تطبيق الرسائل جاهزاً
                </a>
            @endif

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
                <p class="font-semibold">تُحسب عليك رسالة واحدة بتعرفة شركة الاتصالات.</p>
                <p class="mt-1">ولا نقرأ من صندوق رسائلك شيئاً — تصلنا رسالتك أنت وحدها، كما يصل أي مرسِل.</p>
            </div>
        </div>
    @endif

    <a href="{{ route('phone.verify') }}" class="mt-6 block text-center text-sm text-muted underline">
        أو ارجع لإدخال رمز يصلك برسالة
    </a>
</div>

@endsection

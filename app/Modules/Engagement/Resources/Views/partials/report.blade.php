{{--
    زرّ الإبلاغ.

    موضعه آخر الصفحة وبحجم صغير بقصد. ليس تقليلاً من أهميته: الإبلاغ
    فعل نادر يُبحث عنه عند الحاجة، ووضعه بجانب «راسل المعلن» بنفس الحجم
    يجعل جزءاً من الزوار يضغطه بالخطأ — فيتضخّم الطابور ببلاغات لا معنى
    لها ويتوقف الإشراف عن العمل. الظهور بالطلب (details/summary) يكفي.

    والنموذج يظهر للمسجَّل غير المالك وحده — انظر ReportSubmitter.
--}}
@auth
    @if (auth()->id() !== $listing->user_id)
        @php $reporter = app(\App\Modules\Engagement\Services\ReportSubmitter::class); @endphp

        <div class="mt-10 border-t border-line pt-5">
            @if ($reporter->hasOpenReport(auth()->user(), $listing))
                <p class="text-xs text-muted">لديك بلاغ مفتوح على هذا الإعلان — قيد المراجعة.</p>
            @else
                <details class="group">
                    <summary class="cursor-pointer text-xs text-muted transition hover:text-ink">
                        الإبلاغ عن هذا الإعلان
                    </summary>

                    <form method="POST" action="{{ route('listing.report', $listing) }}"
                          class="mt-3 max-w-md rounded-2xl border border-line bg-white p-4">
                        @csrf

                        <label for="report-reason" class="block text-xs font-semibold">السبب</label>
                        <select id="report-reason" name="reason" required
                                class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">
                            <option value="fraud">نصب أو احتيال</option>
                            <option value="prohibited">سلعة ممنوعة</option>
                            <option value="spam">إعلان دعائي أو مكرر</option>
                            <option value="wrong_category">تصنيف خاطئ</option>
                            <option value="duplicate">نسخة مكررة</option>
                            <option value="other">أخرى</option>
                        </select>

                        <label for="report-details" class="mt-3 block text-xs font-semibold">
                            تفاصيل <span class="font-normal text-muted">(مطلوبة مع «أخرى»)</span>
                        </label>
                        <textarea id="report-details" name="details" rows="3" maxlength="500"
                                  placeholder="ما المخالفة تحديداً؟"
                                  class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">{{ old('details') }}</textarea>

                        @error('details')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror

                        <button type="submit"
                                class="mt-3 rounded-lg border border-line px-4 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                            إرسال البلاغ
                        </button>

                        <p class="mt-2 text-[11px] leading-relaxed text-muted">
                            البلاغ الكاذب يُصرَف ويُسجَّل. الإبلاغ على منافس لا يخفي إعلانه.
                        </p>
                    </form>
                </details>
            @endif
        </div>
    @endif
@else
    <p class="mt-10 border-t border-line pt-5 text-xs text-muted">
        <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
        للإبلاغ عن إعلان مخالف.
    </p>
@endauth

{{--
    الأسئلة العامة — تُضمَّن في صفحة الإعلان.

    القالب في وحدة Engagement لا في Catalog، وصفحة الإعلان تضمّنه. البديل
    — نقل الترميز إلى Catalog — يجعل وحدة الكتالوج تعرف شكل الأسئلة
    وأزرارها، فتنتقل الميزة إلى وحدتين. الصفحة تؤلّف؛ الوحدة تملك.

    متغيّراته: $listing, $questions, $canAsk, $isOwner
--}}

<section id="questions" class="rounded-2xl border border-line bg-white p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold">
            أسئلة عن هذا الإعلان
            <span class="tabular-nums text-xs text-muted">({{ $questions->count() }})</span>
        </h2>
        <p class="text-xs text-muted">الأسئلة وأجوبتها تظهر لكل الزوار</p>
    </div>

    @if ($canAsk)
        <form method="POST" action="{{ route('listing.questions.store', $listing) }}" class="mt-4">
            @csrf
            <label class="sr-only" for="question-body">سؤالك</label>
            <textarea id="question-body" name="body" rows="2" maxlength="400" required
                      placeholder="مثال: هل الفحص مسموح؟ وهل السعر قابل للتفاوض؟"
                      class="w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

            @error('body')
                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-[11px] text-muted">
                    لا تكتب رقم هاتفك هنا — السؤال يظهر للجميع. للتواصل الخاص استخدم زرّ «راسل المعلن».
                </p>
                <button type="submit"
                        class="rounded-lg bg-ink px-4 py-2 text-xs font-semibold text-white transition hover:bg-black">
                    أرسل السؤال
                </button>
            </div>
        </form>
    @elseif ($isOwner)
        <p class="mt-3 rounded-xl bg-tint px-3 py-2 text-xs text-muted">
            هذا إعلانك — تظهر لك الأسئلة هنا لتجيب عليها.
        </p>
    @elseif (! auth()->check())
        <p class="mt-3 text-xs text-muted">
            <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
            لطرح سؤال.
        </p>
    @endif

    @if ($questions->isEmpty())
        <p class="mt-4 text-sm text-muted">لا أسئلة بعد.</p>
    @else
        <ul class="mt-4 divide-y divide-line">
            @foreach ($questions as $question)
                <li class="py-4 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium">{{ $question->asker?->displayName() ?? 'مستخدم' }}</p>
                        <p class="text-[11px] text-muted">{{ $question->created_at?->diffForHumans() }}</p>
                    </div>

                    <p class="mt-1 whitespace-pre-line text-sm leading-relaxed">{{ $question->body }}</p>

                    @if ($question->isAnswered())
                        <div class="mt-3 rounded-xl bg-tint p-3">
                            <p class="text-[11px] font-semibold text-muted">جواب المعلن</p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-relaxed">{{ $question->answer }}</p>
                        </div>
                    @elseif ($isOwner)
                        <form method="POST" action="{{ route('questions.answer', $question) }}" class="mt-3">
                            @csrf
                            <label class="sr-only" for="answer-{{ $question->id }}">جوابك</label>
                            <textarea id="answer-{{ $question->id }}" name="answer" rows="2" maxlength="800" required
                                      placeholder="اكتب جوابك — سيظهر لكل الزوار"
                                      class="w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none"></textarea>
                            <button type="submit"
                                    class="mt-2 rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                نشر الجواب
                            </button>
                        </form>
                    @else
                        <p class="mt-2 text-[11px] text-muted">لم يجب المعلن بعد.</p>
                    @endif

                    {{-- الحذف للسائل وحده. المالك لا يحذف سؤالاً محرجاً،
                         وإلا صارت الأسئلة العامة لوحة دعاية لا مصدر معلومة --}}
                    @if (auth()->id() === $question->asker_id)
                        <form method="POST" action="{{ route('questions.destroy', $question) }}" class="mt-2"
                              onsubmit="return confirm('حذف سؤالك؟')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-[11px] text-red-700 hover:underline">حذف سؤالي</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
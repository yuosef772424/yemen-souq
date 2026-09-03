{{--
    هيكل بطاقة الإعلان — نفس أبعاد البطاقة الحقيقية بلا نصوص.

    ═══ لماذا تطابق الأبعاد شرطٌ لا تحسين ═══

    الهيكل غايته أن يشغل **نفس المساحة** التي سيشغلها المحتوى. فإن اختلف
    الارتفاع قفز ما تحته حين تصل البيانات — وهو ارتدادٌ يُشعِر بالبطء أكثر
    مما تُشعِر شاشةٌ فارغة. ولهذا كل مقاس هنا منسوخ من `listing-card`
    حرفياً: `size-28 sm:size-36` للصورة، و`p-3` و`gap-3` للغلاف.

    وأي تعديل في أبعاد البطاقة يجب أن يُنسخ هنا. وهذا الثمن الحقيقي
    للهياكل — سببُ الاكتفاء بهيكل واحد لأكثر انتقال استعمالاً، بدل
    هيكلٍ لكل صفحة (انظر docs/build-log.md).
--}}
<li class="overflow-hidden rounded-2xl border border-line bg-white" aria-hidden="true">
    <div class="flex gap-3 p-3">
        <div class="size-28 shrink-0 animate-pulse rounded-xl bg-tint sm:size-36"></div>

        <div class="flex min-w-0 flex-1 flex-col gap-2 py-1">
            {{-- سطرا العنوان: الثاني أقصر لأن العناوين نادراً ما تملأ سطرين --}}
            <div class="h-4 w-4/5 animate-pulse rounded bg-tint"></div>
            <div class="h-4 w-3/5 animate-pulse rounded bg-tint"></div>

            {{-- السعر: أعرض قليلاً وأثقل، كما يظهر فعلاً --}}
            <div class="mt-1 h-5 w-28 animate-pulse rounded bg-tint"></div>

            {{-- سطر الموقع والتاريخ --}}
            <div class="h-3 w-2/5 animate-pulse rounded bg-tint"></div>

            {{-- سطر الأفعال أسفل البطاقة --}}
            <div class="mt-auto flex gap-2 pt-1">
                <div class="h-8 w-20 animate-pulse rounded-lg bg-tint"></div>
                <div class="h-8 w-20 animate-pulse rounded-lg bg-tint"></div>
            </div>
        </div>
    </div>
</li>

{{--
    نموذج «إجراء بسبب».

    مضمَّن قابل لإعادة الاستخدام لأن القاعدة واحدة في كل إجراء يُضيّق على
    مستخدم: زرّ واحد لا يكفي — الحقل يُجبر المشرف على الكتابة قبل التنفيذ،
    وهذا هو الفرق العملي بين لوحة إشراف ولوحة حجب.

    @param string $action  رابط الإرسال
    @param string $label   نص الزرّ
    @param string $tone    ink|danger
--}}
<form method="POST" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
    @csrf
    <label class="sr-only" for="reason-{{ $id }}">السبب</label>
    <input type="text" id="reason-{{ $id }}" name="reason" required minlength="10" maxlength="255"
           placeholder="السبب — يُقرأ لاحقاً ويراه صاحب الإعلان"
           class="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-xs focus:border-brand focus:outline-none">
    <button type="submit"
            @class([
                'shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white transition',
                'bg-ink hover:bg-black' => ($tone ?? 'ink') === 'ink',
                'bg-red-700 hover:bg-red-800' => ($tone ?? 'ink') === 'danger',
            ])>{{ $label }}</button>
</form>

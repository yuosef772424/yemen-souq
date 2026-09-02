{{--
    تبويب واحد في الشريط السفلي.

    الارتفاع الأدنى ٥٦ بكسل لا ٤٤: الأخير حدّ المساحة القابلة للمس،
    والتبويب هنا يحمل رمزاً ونصّاً معاً — فالحدّ الأدنى يجعل النصّ ملاصقاً
    للحافة. و`active:` بدل `hover:` لأن الجوال بلا مؤشّر: التغذية الراجعة
    الوحيدة المتاحة هي لحظة الضغط نفسها.

    @param  array{label: string, url: string, active: bool, icon: string}  $tab
--}}
<a href="{{ $tab['url'] }}"
   @if ($tab['active']) aria-current="page" @endif
   @class([
       'flex min-h-14 flex-col items-center justify-center gap-1 px-1 py-2 transition active:bg-tint',
       'text-brand' => $tab['active'],
       'text-muted' => ! $tab['active'],
   ])>
    <svg class="size-6" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
         {{-- التبويب النشط يُملأ رمزه لا يُلوَّن وحده: الفرق اللوني وحده
              لا يكفي لمن يصعب عليه تمييز الألوان --}}
         fill="{{ $tab['active'] ? 'currentColor' : 'none' }}"
         fill-opacity="{{ $tab['active'] ? '0.15' : '0' }}">
        {!! $tab['icon'] !!}
    </svg>
    <span class="text-[10px] font-medium leading-none">{{ $tab['label'] }}</span>
</a>

@extends('shared::layouts.app')

@section('title', 'تفضيلات الإشعارات')

@section('content')

<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="text-xl font-bold">تفضيلات الإشعارات</h1>
        <p class="mt-1 text-sm text-muted">
            ما يصلك، وعلى أي قناة.
            <a href="{{ route('account.settings') }}" class="font-medium text-brand-dark underline">عودة إلى إعدادات الحساب</a>
        </p>
    </div>

    <form method="POST" action="{{ route('account.notifications.preferences.update') }}"
          class="rounded-2xl border border-line bg-white p-5">
        @csrf
        @method('PUT')

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-right text-xs text-muted">
                        <th scope="col" class="pb-2 font-medium">الإشعار</th>
                        @foreach ($channels as $channel)
                            <th scope="col" class="pb-2 text-center font-medium">{{ $channel->label() }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($types as $type)
                        <tr class="border-b border-line/60 last:border-0">
                            <th scope="row" class="py-3 text-right font-normal">
                                {{ $type->label() }}
                                @if ($type->isMandatory())
                                    <span class="block text-xs text-muted">
                                        يصلك داخل الموقع دائماً — قرار الإشراف يخصّ إعلانك، وإسكاته يعني
                                        أن يختفي إعلانك بلا أن تعرف لماذا.
                                    </span>
                                @endif
                            </th>

                            @foreach ($channels as $channel)
                                @php
                                    $locked = $type->isMandatory() && $channel->value === 'in_app';
                                    $checked = $locked || $current[$type->value][$channel->value];
                                @endphp
                                <td class="py-3 text-center">
                                    <label class="inline-flex items-center justify-center">
                                        <span class="sr-only">{{ $type->label() }} — {{ $channel->label() }}</span>
                                        <input type="checkbox"
                                               name="preferences[{{ $type->value }}][{{ $channel->value }}]"
                                               value="1"
                                               @checked($checked)
                                               @disabled($locked)
                                               class="size-4 rounded border-line disabled:opacity-50">
                                    </label>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (collect($channels)->contains(fn ($channel) => $channel->value === 'sms'))
            <p class="mt-4 text-xs text-muted">
                قد تصل الرسالة النصية متأخرة بضع ثوانٍ.
            </p>
        @endif

        <button type="submit"
                class="mt-4 rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">
            حفظ التفضيلات
        </button>
    </form>

</div>

@endsection

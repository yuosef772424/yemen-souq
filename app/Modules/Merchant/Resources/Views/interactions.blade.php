@extends('shared::layouts.app')

@section('title', 'التفاعل مع إعلاناتي')

@section('content')

<h1 class="text-xl font-bold">التفاعل مع إعلاناتي</h1>
<p class="mt-1 text-sm text-muted">الأحدث أولاً — أسئلة ورسائل وحفظ في المفضلة، في مكان واحد.</p>

@if ($items->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا تفاعل بعد على إعلاناتك.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($items as $item)
            <li @class([
                    'rounded-2xl border bg-white p-4',
                    'border-amber-300' => $item['needs_reply'],
                    'border-line' => ! $item['needs_reply'],
                ])>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span @class([
                        'rounded-md px-2 py-0.5 text-[11px] font-semibold',
                        'bg-amber-100 text-amber-900' => $item['type'] === 'question',
                        'bg-brand/10 text-brand-dark' => $item['type'] === 'message',
                        'bg-tint text-muted' => $item['type'] === 'favorite',
                    ])>
                        {{ ['question' => 'سؤال عام', 'message' => 'رسالة', 'favorite' => 'أُضيف إلى المفضلة'][$item['type']] }}
                    </span>

                    <span class="text-[11px] text-muted">{{ $item['at']->diffForHumans() }}</span>
                </div>

                <a href="{{ route('listing.show', $item['listing_slug']) }}"
                   class="mt-2 block truncate text-xs font-medium text-brand-dark hover:underline">
                    {{ $item['listing_title'] }}
                </a>

                @if ($item['body'] !== null)
                    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed">{{ $item['body'] }}</p>
                @endif

                @if ($item['needs_reply'])
                    <p class="mt-2 text-xs">
                        @if ($item['type'] === 'question')
                            {{-- الرابط إلى قسم الأسئلة في صفحة الإعلان: مسار موجود بيقين،
                                 ولا يعتمد على اسم مسار صندوق الأسئلة الذي قد يختلف --}}
                            <a href="{{ route('listing.show', $item['listing_slug']) }}#questions"
                               class="font-medium text-brand-dark hover:underline">
                                أجب على الإعلان →
                            </a>
                            <span class="text-muted">الجواب العام يُغني عشرة مشترين عن السؤال.</span>
                        @else
                            <a href="{{ route('account.messages') }}" class="font-medium text-brand-dark hover:underline">
                                ردّ من الرسائل →
                            </a>
                        @endif
                    </p>
                @endif
            </li>
        @endforeach
    </ul>

    {{--
        بلا ترقيم صفحات بقصد — انظر تعليق InteractionFeed. التاريخ الكامل
        في صفحته: الأسئلة في صندوقها والرسائل في المحادثات.
    --}}
    <p class="mt-5 text-xs text-muted">
        يُعرض آخر {{ \App\Modules\Merchant\Services\InteractionFeed::TOTAL }} تفاعلاً.
        الأقدم في صفحة كل إعلان و<a href="{{ route('account.messages') }}" class="text-brand-dark hover:underline">الرسائل</a>.
    </p>
@endif

@endsection

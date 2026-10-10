import Alpine from 'alpinejs';

/**
 * معاينة حية للنطاق السعري بعد التحويل.
 *
 * الحساب يجري في المتصفح بأسعار مُمرَّرة من الخادم — بلا طلبات إضافية.
 * السبب: أغلب المستخدمين على اتصال بطيء، وطلب لكل ضغطة مفتاح يجعل
 * الحقل يبدو معطوباً.
 */
Alpine.data('priceFilter', (rates, initialCurrency, baseSymbol) => ({
    currency: initialCurrency,
    min: new URLSearchParams(location.search).get('min') || '',
    max: new URLSearchParams(location.search).get('max') || '',

    get preview() {
        const rate = rates[this.currency];
        const baseCode = Object.keys(rates).find((code) => Number(rates[code]) === 1);

        if (!rate || this.currency === baseCode) {
            return '';
        }

        const min = parseFloat(this.min);
        const max = parseFloat(this.max);

        if (isNaN(min) && isNaN(max)) {
            return '';
        }

        const format = (value) =>
            new Intl.NumberFormat('ar-YE', { maximumFractionDigits: 0 }).format(
                Math.round(value * rate),
            );

        if (!isNaN(min) && !isNaN(max)) {
            return `يقابل ${format(min)} – ${format(max)} ${baseSymbol}`;
        }

        if (!isNaN(min)) {
            return `من ${format(min)} ${baseSymbol} وأعلى`;
        }

        return `حتى ${format(max)} ${baseSymbol}`;
    },
}));

/**
 * نموذج نشر الإعلان: معاينة الصور قبل الرفع + تحقق أولي في المتصفح.
 *
 * التحقق هنا لا يُغني عن تحقق الخادم — هو فقط يوفّر على المستخدم
 * رفع 40 ميجابايت ليكتشف بعدها أن الملف مرفوض. التحقق الملزم يبقى
 * في StoreListingRequest.
 */
Alpine.data('listingForm', (maxImages, categoryId = '', attributeCategories = []) => ({
    priceType: 'fixed',
    items: [],
    notice: '',

    /**
     * التصنيف المختار — تُبنى عليه حقول المواصفات.
     *
     * يبدأ من old() لا فارغاً: بعد رفض التحقق تُعاد الصفحة، وتصفير
     * التصنيف هنا يُخفي الحقول التي كتب فيها المعلن قيماً — فتبدو
     * أخطاؤها معلّقة على حقول غير مرئية.
     */
    categoryId: categoryId,

    /** التصنيفات التي لها مواصفات — بغيرها لا يُعرض القسم أصلاً */
    attributeCategories: attributeCategories.map(String),

    get needsPrice() {
        return this.priceType === 'fixed' || this.priceType === 'negotiable';
    },

    get hasAttributes() {
        return this.attributeCategories.includes(String(this.categoryId));
    },

    preview(event) {
        this.items.forEach((item) => URL.revokeObjectURL(item.url));
        this.items = [];
        this.notice = '';

        const files = Array.from(event.target.files || []);
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        const maxBytes = 8 * 1024 * 1024;
        const problems = [];

        files.forEach((file) => {
            if (!allowed.includes(file.type)) {
                problems.push(`${file.name}: صيغة غير مدعومة`);
                return;
            }

            if (file.size > maxBytes) {
                problems.push(`${file.name}: أكبر من 8 ميجابايت`);
                return;
            }

            this.items.push({ url: URL.createObjectURL(file), name: file.name });
        });

        if (files.length > maxImages) {
            problems.push(`اخترت ${files.length} صورة — الحد الأقصى ${maxImages}.`);
        }

        this.notice = problems.join(' · ');
    },
}));

/**
 * كشف رقم التواصل عبر الخادم.
 *
 * الرقم لا يُرسَل في HTML الصفحة — يُجلب عند الطلب. فائدتان:
 * تسجيل أقوى إشارة نية شراء، وتقليل حصاد الأرقام آلياً.
 */
Alpine.data('contactReveal', (listingId) => ({
    phone: '',
    loading: false,
    error: '',

    async reveal() {
        if (this.loading || this.phone) {
            return;
        }

        this.loading = true;
        this.error = '';

        try {
            const response = await fetch(`/listing/${listingId}/contact`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('unavailable');
            }

            const data = await response.json();
            this.phone = data.phone;
        } catch {
            this.error = 'تعذّر جلب الرقم. حاول مرة أخرى.';
        } finally {
            this.loading = false;
        }
    },
}));

/**
 * نموذج الرسالة الخاصة (صفحة الإعلان وصفحة المحادثة).
 *
 * الإرسال يبقى POST عادياً يعمل بلا JavaScript — والحماية من التكرار
 * في الخادم: الحقل المخفي client_id يُولَّد عند عرض النموذج، فالتحديث
 * بعد الإرسال لا يُنشئ رسالة ثانية. وهذا المكوّن طبقة تحسين فوق ذلك:
 *
 * - `sending` يعطّل الزرّ ويمنع ضغطةً ثانية قبل أن تنتقل الصفحة.
 * - المعرّف يُولَّد مرة واحدة لكل رسالة، ويبقى هو نفسه عند إعادة المحاولة
 *   (فشل شبكة، رفض تحقق): لا يتبدّل إلا حين تُحمَّل صفحة جديدة بنموذج جديد.
 *   لو خلا الحقل لأي سبب يُولَّد هنا — وبديل لـcrypto.randomUUID لأنه
 *   لا يوجد خارج السياق الآمن (http).
 * - الاستعادة من ذاكرة المتصفح بالزرّ الخلفي تُعيد الصفحة والزرّ معطَّل،
 *   فنفكّه في pageshow. والمستمع والمؤقّت يُسجَّلان مرة واحدة ويُزالان
 *   في destroy.
 */
const MESSAGE_SEND_TIMEOUT_MS = 15000;

function newClientId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    // RFC 4122 v4
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

Alpine.data('messageForm', () => ({
    sending: false,
    timer: null,
    onPageShow: null,

    init() {
        // مرة واحدة هنا — لا في submit، وإلا تراكمت المستمعات
        this.onPageShow = (event) => {
            if (event.persisted) {
                this.reset();
            }
        };

        window.addEventListener('pageshow', this.onPageShow);

        const field = this.$refs.clientId;

        if (field && !field.value) {
            field.value = newClientId();
        }
    },

    destroy() {
        window.removeEventListener('pageshow', this.onPageShow);
        this.clearTimer();
    },

    submit(event) {
        if (this.sending) {
            event.preventDefault();
            return;
        }

        this.sending = true;

        // الشبكة التي تنقطع تُبقي الصفحة حيث هي: بعد مهلة يُعاد تفعيل الزرّ،
        // والمحاولة التالية تحمل المعرّف نفسه فلا تُنشئ رسالتين
        this.clearTimer();
        this.timer = setTimeout(() => this.reset(), MESSAGE_SEND_TIMEOUT_MS);
    },

    reset() {
        this.sending = false;
        this.clearTimer();
    },

    clearTimer() {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    },
}));

/**
 * «شوهدت مؤخراً»: تعمل بالكامل من طرف العميل عبر localStorage — بلا
 * حساب وبلا استعلام خادم. الشكل المخزَّن (id/title/price/image/url) هو
 * نفسه عقد الربط المستقبلي بحساب المستخدم إن احتاج المنتج ذلك لاحقاً.
 */
const RECENT_VIEWS_KEY = 'recently_viewed';
const RECENT_VIEWS_LIMIT = 12;

function readRecentViews() {
    try {
        const stored = JSON.parse(localStorage.getItem(RECENT_VIEWS_KEY) ?? '[]');
        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
}

/** تُستخدم في صفحة تفاصيل الإعلان لتسجيل زيارته */
Alpine.data('rememberView', (item) => ({
    init() {
        if (!item?.id) {
            return;
        }

        const list = readRecentViews().filter((existing) => existing.id !== item.id);
        list.unshift(item);

        localStorage.setItem(RECENT_VIEWS_KEY, JSON.stringify(list.slice(0, RECENT_VIEWS_LIMIT)));
    },
}));

/** تُستخدم في صفحة التصفح لعرض القائمة المخزَّنة */
Alpine.data('recentlyViewed', () => ({
    items: readRecentViews(),
}));

/**
 * قائمة منسدلة في شريط ملاحّة قابل للتمرير أفقياً.
 *
 * ═══ لماذا توجد هذه الدالة أصلاً ═══
 *
 * `overflow-x-auto` بلا `overflow-y` صريحة تجعل المتصفح — حسب مواصفة CSS —
 * يحسب `overflow-y` الفعلي أيضاً `auto` لا `visible`. فأي قائمة منسدلة
 * `absolute` داخل الشريط تُقصّ عند حافته السفلية (٤٤px هنا) مهما كان
 * ارتفاعها — قائمة المحافظات كانت تُفتح فعلياً (`open: true`، الروابط
 * موجودة في DOM) لكن مرئيّة بمقدار بكسلين فقط قبل القصّ. هذا هو ما بلّغ
 * عنه المستخدم: "المحافظات لا تظهر".
 *
 * والحل ليس `overflow-y-visible` على الشريط — المواصفة تمنع التقاءهما.
 * الحل نقل اللوحة خارج الشريط تماماً (`x-teleport="body"`) وحساب موضعها
 * من إحداثيات الزرّ الفعلية عبر `getBoundingClientRect()`، التي لا تتأثر
 * باتجاه RTL أصلاً — فتعمل بلا تمييز اتجاه.
 *
 * ═══ لماذا لم يظهر نفس العطل في قائمة الأقسام ═══
 *
 * غلافها لم يحمل `relative`، فصار السياق المرجعي لموضعها الرأس نفسه لا
 * الشريط — إفلاتٌ بالمصادفة لا بالتصميم. تعميم هذه الدالة عليها أيضاً
 * يزيل الاعتماد على تلك المصادفة الهشة.
 */
Alpine.data('headerDropdown', (align = 'end') => ({
    open: false,
    top: 0,
    right: null,
    left: null,

    toggle() {
        this.open = !this.open;

        if (this.open) {
            this.$nextTick(() => this.reposition());
        }
    },

    close() {
        this.open = false;
    },

    reposition() {
        const rect = this.$refs.trigger.getBoundingClientRect();
        this.top = rect.bottom + 4;

        // end في RTL يعني الحافة اليمنى — نفس ما كانت تفعله start-0 محلياً
        if (align === 'end') {
            this.right = window.innerWidth - rect.right;
            this.left = null;
        } else {
            this.left = rect.left;
            this.right = null;
        }
    },

    get panelStyle() {
        return {
            top: `${this.top}px`,
            right: this.right !== null ? `${this.right}px` : 'auto',
            left: this.left !== null ? `${this.left}px` : 'auto',
        };
    },
}));

/**
 * معرض صور الإعلان — يُبدَّل بالسحب لا بالضغط وحده.
 *
 * ═══ لماذا التمرير الأصلي لا مكتبة سحب ═══
 *
 * كان المعرض يُظهر صورة واحدة (`x-show`) ويُبدّلها بالضغط على مصغّرة —
 * وهو تفاعلٌ لا يخطر ببال من يتصفّح على هاتفه: إصبعه يسحب أولاً.
 *
 * والبديل المرفوض مكتبة سحب (Swiper وأمثالها): تحسب الإزاحة بـJS في كل
 * إطار، فتفقد الزخم واللدونة اللذين يعطيهما النظام مجاناً — ويظهر ذلك
 * فوراً على الأجهزة الضعيفة، وهي الغالبة هنا. و`scroll-snap` من CSS
 * يعطي السلوك نفسه بلا سطر JS للحركة: تسارع، وارتداد عند الحافة،
 * والتقاط عند أقرب صورة.
 *
 * ═══ ولماذا scrollIntoView لا حساب scrollLeft ═══
 *
 * `scrollLeft` في RTL يعطي قيماً سالبة في متصفحات وموجبةً تنازلية في
 * غيرها — خلافٌ تاريخي لا يُحسم بتجربة متصفح واحد. و`scrollIntoView`
 * بـ`inline: 'center'` يتولّى الاتجاه بنفسه، فيعمل في الاتجاهين بلا
 * تمييز. و`block: 'nearest'` يمنعه من تمرير الصفحة رأسياً معه.
 */
Alpine.data('gallery', () => ({
    active: 0,

    go(index) {
        this.$refs.track.children[index]?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'center',
        });
    },

    /**
     * التمرير هو مصدر الحقيقة لا الضغط.
     *
     * المستخدم يسحب بإصبعه فتتغيّر الصورة المعروضة بلا أن يضغط شيئاً —
     * فلو كان `active` يتغيّر بالضغط وحده لبقيت المصغّرة المميّزة تشير
     * إلى صورة غادرت الشاشة.
     */
    sync() {
        const track = this.$refs.track;

        if (track.clientWidth > 0) {
            this.active = Math.round(Math.abs(track.scrollLeft) / track.clientWidth);
        }
    },
}));

/**
 * ═══════════════════════════════════════════════════════════════════
 * تغذية راجعة أثناء التنقّل
 * ═══════════════════════════════════════════════════════════════════
 *
 * ═══ المشكلة، وحدّ ما يمكن حلّه هنا ═══
 *
 * الموقع يُصيَّر على الخادم: كل ضغطة تُحمّل صفحة كاملة. وبين الضغطة ووصول
 * أول بايت يبقى المتصفح عارضاً **الصفحة القديمة** بلا أي إشارة — فتبدو
 * الضغطة ضائعة، ويضغط المستخدم ثانيةً.
 *
 * ولا يمكن هنا رسم هيكل الصفحة **القادمة**: لم تصل بعد، ورسمُه يعني
 * نسخةً هيكلية لكل صفحة تُصان بالتوازي مع أصلها — مصدر حقيقة ثانٍ ينحرف
 * عند أول تعديل. فالمعالجة على مستويين بحسب ما نعرفه فعلاً:
 *
 *   1. **أي تنقّل**: شريط تقدّم علوي. لا يحتاج معرفة الوجهة.
 *   2. **الفلترة والتصنيف داخل صفحة النتائج**: نعرف يقيناً أن الوجهة
 *      صفحة نتائج، وأن المتغيّر هو الشبكة وحدها — فتُستبدل بهيكل مطابق
 *      فوراً. هيكل واحد يغطّي أكثر انتقال استعمالاً.
 */

/** يبدأ من ٩٠٪ ولا يكتمل: الاكتمال يقع بمغادرة الصفحة نفسها */
function navigationFeedback() {
    const bar = document.createElement('div');
    bar.className = 'nav-progress';
    document.body.appendChild(bar);

    let running = false;

    const start = () => {
        if (running) {
            return;
        }

        running = true;
        bar.classList.add('is-active');
        swapResultsForSkeleton();
    };

    /**
     * استبدال النتائج بالهيكل — في صفحة النتائج وحدها.
     *
     * `<template>` لا يُرسَم محتواه ولا يُقرأ بالقارئ الشاشي حتى يُستنسخ،
     * فوجوده في كل صفحة نتائج بلا تكلفة عرض.
     */
    function swapResultsForSkeleton() {
        const results = document.querySelector('[data-results]');
        const template = document.querySelector('[data-results-skeleton]');

        if (!results || !template) {
            return;
        }

        // الارتفاع يُثبَّت قبل الاستبدال: هيكلٌ أقصر من النتائج يقفز
        // بالصفحة لأعلى، وهو ارتداد يُشعر بالبطء أكثر من الانتظار نفسه
        results.style.minHeight = `${results.offsetHeight}px`;
        results.replaceChildren(template.content.cloneNode(true));
    }

    /**
     * ما يُستثنى من الشريط — وكلٌّ منها كان سيُنتج شريطاً لا ينتهي:
     * زرّ الفأرة الأوسط وCtrl يفتحان تبويباً جديداً والصفحة الحالية باقية،
     * والروابط الخارجية والتنزيلات تغادر نطاقنا، والمرساة (#) لا تُحمّل
     * شيئاً أصلاً.
     */
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a[href]');

        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) {
            return;
        }

        const url = new URL(link.href, location.href);

        if (url.origin !== location.origin) {
            return;
        }

        // نفس الصفحة أو مجرّد مرساة فيها
        if (url.href === location.href || (url.hash && url.pathname === location.pathname)) {
            return;
        }

        start();
    });

    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented) {
            start();
        }
    });

    /**
     * العودة بالزرّ الخلفي تُعيد الصفحة من ذاكرة المتصفح كما غادرَتْ —
     * أي والشريط ظاهر والنتائج مستبدَلة بالهيكل. `pageshow` مع `persisted`
     * هي الإشارة الوحيدة التي تُميّز هذه الحالة، وبلاها يرى العائد هيكلاً
     * لا يمتلئ أبداً.
     */
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            location.reload();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', navigationFeedback);
} else {
    navigationFeedback();
}

window.Alpine = Alpine;
Alpine.start();
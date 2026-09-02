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

window.Alpine = Alpine;
Alpine.start();
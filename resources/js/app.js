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

window.Alpine = Alpine;
Alpine.start();
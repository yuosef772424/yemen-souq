/*
 * عامل الخدمة — يجعل الموقع قابلاً للتثبيت، ويُسرّع الأصول الثابتة.
 *
 * ═══ ما لا يُخزَّن عمداً: صفحات HTML ═══
 *
 * هذا سوق فيه حسابات ومحادثات ورسائل خاصة. وتخزين استجابة صفحةٍ كاملة
 * يعني — على جهاز يستعمله أكثر من شخص، أو بعد تسجيل خروج — احتمال تقديم
 * صفحة تحمل بيانات صاحبها السابق. والخطر لا يستحق المكسب: تخزين الأصول
 * وحده يعطي معظم السرعة بلا أيٍّ من ذلك.
 *
 * فالتنقّل يمرّ إلى الشبكة دائماً، وحين تنقطع تُعرَض صفحة «لا اتصال»
 * وحدها — لا نسخة قديمة تبدو حيّة.
 *
 * ═══ ولماذا CACHE_VERSION ═══
 *
 * تغيير الرقم يُبطل المخزَّن كله عند أول تفعيل. وبلاه يبقى أصلٌ قديم
 * مخزَّناً بعد نشر جديد، فيرى المستخدم واجهة نصفها محدَّث ونصفها لا —
 * وهو عطل يستحيل تشخيصه من السجلّات.
 */

const CACHE_VERSION = 'souq-v1';
const OFFLINE_URL = '/offline';

/** ما يُخزَّن مسبقاً: القشرة الأدنى التي تجعل صفحة الانقطاع تبدو كاملة */
const PRECACHE = [
    OFFLINE_URL,
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_VERSION)
            // addAll تفشل كلها إن فشل عنصر — والفشل هنا يمنع التثبيت
            // أصلاً، فتُضاف العناصر فرادى ويُتجاوز ما تعذّر
            .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // GET وحدها: تخزين POST بلا معنى، وتخزين رد نموذجٍ خطر
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // النطاق الخاص بنا وحده — لا نتدخّل في الخطوط أو أي طرف ثالث
    if (url.origin !== self.location.origin) {
        return;
    }

    // التنقّل: شبكة دائماً، وصفحة انقطاع عند الفشل — بلا تخزين
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );

        return;
    }

    // الأصول الثابتة وحدها: مخزَّنة أولاً ثم تُحدَّث في الخلفية
    const isAsset = url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/icons/');

    if (!isAsset) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => cached);

            return cached || network;
        }),
    );
});

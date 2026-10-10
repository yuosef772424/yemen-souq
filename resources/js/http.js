/**
 * غلاف fetch لواجهة ‎/api‎ — يفهم أخطاء RFC 9457 (problem+json).
 *
 * ═══ لماذا غلاف ═══
 *
 * الخادم يردّ على كل خطأ في ‎/api/*‎ بجسد موحَّد (`type`, `title`, `status`,
 * `detail`, `code`, `message`, `errors`, `retry_after`)، لكن `fetch` وحده:
 *  - لا يرمي على 4xx/5xx، فيصير كل مستدعٍ يكرّر فحص `response.ok`؛
 *  - يفشل في `response.json()` إن عاد HTML (بوابة، 502 من وسيط) فيظهر
 *    العطل «Unexpected token <» بدل «حاول بعد قليل»؛
 *  - ينسى الترويسة `Accept: application/json` فيعود HTML لخطأ ويب.
 *
 * فالدالة **لا ترمي أبداً**: تعيد دائماً `{ ok, status, data, problem }`
 * بنفس الشكل نجاحاً وفشلاً وانقطاعَ شبكة.
 *
 * ═══ الاستعمال ═══
 *
 * ```js
 * import { http, fieldErrors } from './http.js';
 *
 * const res = await http.post('/api/v1/auth/login', { identifier, password });
 * if (res.ok) {
 *     token = res.data.token;
 * } else if (res.problem.code === 'too_many_attempts') {
 *     wait(res.problem.retry_after);
 * } else {
 *     this.errors = fieldErrors(res.problem);   // { password: 'البيانات غير صحيحة.' }
 * }
 * ```
 *
 * الملف **غير مربوط** بـapp.js بعد: يُستورد حيث يُحتاج.
 *
 * @module http
 */

/**
 * جسد خطأ موحَّد — يطابق ‎docs/reference/api-errors.md‎.
 * يُبنى دائماً بكل حقوله، حتى حين لا يكون الخادم هو من ردّ (شبكة، HTML).
 *
 * @typedef {Object} Problem
 * @property {string} type       مُعرِّف النوع: `urn:yemen-souq:problem:<code>`
 * @property {string} title      عنوان عربي قصير ثابت للنوع
 * @property {number} status     حالة HTTP (0 عند انقطاع الشبكة)
 * @property {string} detail     رسالة عربية آمنة تُعرض للمستخدم
 * @property {string} code       رمز snake_case ثابت — ما يُطابقه الكود
 * @property {string} message    نسخة من `detail` (توافق مع العملاء الحاليين)
 * @property {Object<string, string[]>} errors  `{حقل: [رسائل]}` — فارغ إن لم يوجد
 * @property {number|null} retry_after  ثوانٍ قبل إعادة المحاولة (429)
 * @property {string|null} instance     مسار الطلب الذي فشل
 */

/**
 * @typedef {Object} HttpResult
 * @property {boolean} ok            هل الحالة 2xx
 * @property {number} status         حالة HTTP، أو 0 عند فشل الاتصال
 * @property {*} data                الجسد المحلَّل عند النجاح، وإلا null
 * @property {Problem|null} problem  وصف الخطأ عند الفشل، وإلا null
 */

/**
 * @typedef {Object} HttpOptions
 * @property {string} [method='GET']
 * @property {Object|FormData|string|null} [body]  كائن → JSON، وFormData يُمرَّر كما هو
 * @property {Object<string, string>} [headers]    ترويسات إضافية
 * @property {Object<string, *>} [query]           معاملات الرابط (تُهمَل القيم null/undefined)
 * @property {AbortSignal} [signal]
 */

const PROBLEM_TYPE_PREFIX = 'urn:yemen-souq:problem:';

const NETWORK_DETAIL = 'تعذّر الاتصال بالخادم. تحقّق من اتصالك ثم أعد المحاولة.';
const SERVER_DETAIL = 'حدث خطأ غير متوقع. حاول مرة أخرى بعد قليل.';

/**
 * رمز التحقق من الـmeta — يعيد null خارج المتصفح أو بلا الوسم.
 *
 * @returns {string|null}
 */
function csrfToken() {
    if (typeof document === 'undefined') {
        return null;
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || null;
}

/**
 * هل نوع المحتوى JSON — يشمل `application/problem+json` و`application/*+json`.
 *
 * @param {string|null} contentType
 * @returns {boolean}
 */
function isJson(contentType) {
    return /\bjson\b|\+json\b/i.test(contentType || '');
}

/**
 * رمز احتياطي من الحالة حين لا يحمل الرد رمزاً — يطابق ProblemCode::forStatus في الخادم.
 *
 * @param {number} status
 * @returns {string}
 */
function codeForStatus(status) {
    const map = {
        0: 'network_error',
        400: 'bad_request',
        401: 'unauthenticated',
        403: 'forbidden',
        404: 'not_found',
        405: 'method_not_allowed',
        409: 'conflict',
        413: 'payload_too_large',
        415: 'unsupported_media_type',
        419: 'page_expired',
        422: 'validation_failed',
        429: 'rate_limited',
        503: 'service_unavailable',
    };

    if (map[status]) {
        return map[status];
    }

    return status >= 500 ? 'server_error' : 'request_failed';
}

/**
 * يجعل `errors` دائماً `{حقل: [نصوص]}` — يقبل نصاً مفرداً بدل قائمة.
 *
 * @param {*} errors
 * @returns {Object<string, string[]>}
 */
function normalizeErrors(errors) {
    const out = {};

    if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
        return out;
    }

    for (const [field, messages] of Object.entries(errors)) {
        const list = (Array.isArray(messages) ? messages : [messages])
            .filter((m) => typeof m === 'string' && m !== '');

        if (list.length > 0) {
            out[field] = list;
        }
    }

    return out;
}

/**
 * يبني `Problem` كاملاً من أي جسد: problem+json، أو شكل لارافل القديم
 * (`{message, errors}` / `{message, code}`)، أو لا شيء.
 *
 * @param {*} body       الجسد المحلَّل، أو null
 * @param {number} status
 * @param {Headers|null} headers
 * @returns {Problem}
 */
function toProblem(body, status, headers) {
    const src = body && typeof body === 'object' ? body : {};
    const code = typeof src.code === 'string' && src.code !== '' ? src.code : codeForStatus(status);

    const detail =
        (typeof src.detail === 'string' && src.detail) ||
        (typeof src.message === 'string' && src.message) ||
        (status === 0 ? NETWORK_DETAIL : SERVER_DETAIL);

    const header = headers?.get('Retry-After');
    const fromHeader = header !== null && header !== undefined && /^\d+$/.test(header) ? Number(header) : null;
    const retry = Number.isFinite(Number(src.retry_after)) && src.retry_after !== null
        ? Number(src.retry_after)
        : fromHeader;

    return {
        type: typeof src.type === 'string' ? src.type : PROBLEM_TYPE_PREFIX + code,
        title: typeof src.title === 'string' ? src.title : '',
        status,
        detail,
        code,
        message: detail,
        errors: normalizeErrors(src.errors),
        retry_after: retry,
        instance: typeof src.instance === 'string' ? src.instance : null,
    };
}

/**
 * يضيف معاملات الرابط ويتجاوز القيم الفارغة.
 *
 * @param {string} url
 * @param {Object<string, *>} [query]
 * @returns {string}
 */
function withQuery(url, query) {
    if (!query) {
        return url;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value !== null && value !== undefined) {
            params.append(key, String(value));
        }
    }

    const qs = params.toString();

    if (qs === '') {
        return url;
    }

    return url + (url.includes('?') ? '&' : '?') + qs;
}

/**
 * ينفّذ طلباً ويعيد `{ ok, status, data, problem }` — لا يرمي.
 *
 * - يرسل `Accept: application/json` و`X-Requested-With` ورمز CSRF من
 *   `<meta name="csrf-token">` (يُهمَل إن غاب).
 * - كائن `body` يُرسَل JSON؛ `FormData` يُمرَّر كما هو (المتصفح يضبط الحدود).
 * - `credentials: 'same-origin'` — ‎/api‎ نفس الأصل، فتُرسَل الكوكيز إن وُجدت. لكنها
 *   لا تصادِق: لا `statefulApi()` ولا `EnsureFrontendRequestsAreStateful`، فمسارات
 *   `auth:sanctum` تحتاج `Authorization: Bearer <token>` تمرّره عبر `headers`.
 * - أسماء الترويسات لا تتأثر بحالة الأحرف: ما يمرّره المستدعي يحلّ محلّ الافتراضي
 *   (`x-csrf-token` تساوي `X-CSRF-TOKEN`) بدل أن تُرسَل الترويستان معاً.
 *
 * @param {string} url
 * @param {HttpOptions} [options]
 * @returns {Promise<HttpResult>}
 */
export async function request(url, options = {}) {
    const { method = 'GET', body = null, headers = {}, query, signal } = options;
    const verb = method.toUpperCase();

    // Headers لا الكائن العادي: أسماؤها غير حسّاسة لحالة الأحرف، فـ`set`/`has`
    // يستبدلان لا يكرّران (كائنان بمفتاحَي `X-CSRF-TOKEN` و`x-csrf-token`
    // يُدمجان عند الإرسال في «t, t» فيرفضه الخادم 419).
    const sent = new Headers({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    });

    for (const [name, value] of new Headers(headers)) {
        sent.set(name, value);
    }

    const token = csrfToken();

    if (token && !sent.has('X-CSRF-TOKEN')) {
        sent.set('X-CSRF-TOKEN', token);
    }

    let payload;

    if (body !== null && body !== undefined && verb !== 'GET' && verb !== 'HEAD') {
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            payload = body;
        } else if (typeof body === 'string') {
            payload = body;
        } else {
            payload = JSON.stringify(body);
            if (!sent.has('Content-Type')) {
                sent.set('Content-Type', 'application/json');
            }
        }
    }

    let response;

    try {
        response = await fetch(withQuery(url, query), {
            method: verb,
            headers: sent,
            body: payload,
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        // الإلغاء المقصود ليس عطل شبكة: يُعاد للمستدعي ليعرف أنه ألغى
        if (error?.name === 'AbortError') {
            throw error;
        }

        return { ok: false, status: 0, data: null, problem: toProblem(null, 0, null) };
    }

    let parsed = null;

    if (response.status !== 204 && isJson(response.headers.get('Content-Type'))) {
        try {
            parsed = await response.json();
        } catch {
            parsed = null;
        }
    }

    if (response.ok) {
        return { ok: true, status: response.status, data: parsed, problem: null };
    }

    return { ok: false, status: response.status, data: null, problem: toProblem(parsed, response.status, response.headers) };
}

/**
 * اختصارات الأفعال: `http.get(url, {query})` و`http.post(url, body, options)`…
 */
export const http = {
    /** @param {string} url @param {HttpOptions} [options] @returns {Promise<HttpResult>} */
    get: (url, options = {}) => request(url, { ...options, method: 'GET' }),
    /** @param {string} url @param {*} [body] @param {HttpOptions} [options] @returns {Promise<HttpResult>} */
    post: (url, body = null, options = {}) => request(url, { ...options, method: 'POST', body }),
    /** @param {string} url @param {*} [body] @param {HttpOptions} [options] @returns {Promise<HttpResult>} */
    put: (url, body = null, options = {}) => request(url, { ...options, method: 'PUT', body }),
    /** @param {string} url @param {*} [body] @param {HttpOptions} [options] @returns {Promise<HttpResult>} */
    patch: (url, body = null, options = {}) => request(url, { ...options, method: 'PATCH', body }),
    /** @param {string} url @param {HttpOptions} [options] @returns {Promise<HttpResult>} */
    delete: (url, options = {}) => request(url, { ...options, method: 'DELETE' }),
};

/**
 * يحوّل `problem.errors` إلى أخطاء حقول جاهزة لقالب Alpine.
 *
 * الافتراضي: **أول رسالة** لكل حقل (نصّ) — ما تعرضه الواجهة تحت الحقل:
 * `<p x-text="errors.phone">`. و`{ all: true }` يعيد القوائم كاملة.
 * وإن لم تحمل المشكلة أخطاء حقول (مثل 429 أو 500) يعود `{}` — ويُعرَض
 * `problem.detail` في موضع عام بدل ذلك.
 *
 * @param {Problem|null|undefined} problem
 * @param {{all?: boolean}} [options]
 * @returns {Object<string, string>|Object<string, string[]>}
 */
export function fieldErrors(problem, { all = false } = {}) {
    const errors = normalizeErrors(problem?.errors);

    if (all) {
        return errors;
    }

    return Object.fromEntries(Object.entries(errors).map(([field, list]) => [field, list[0]]));
}

export default http;

#!/data/data/com.termux/files/usr/bin/env bash
#
# souq-sms-worker.sh — عامل الرسائل النصية على هاتف أندرويد (Termux).
#
# يسحب دفعةً من طابور الخادم، يرسلها ببطاقة الهاتف، ثم يؤكّد ما نجح
# وما فشل. حلقة واحدة لا غير.
#
# ═══ لماذا يسحب ولا يُدفَع إليه ═══
#
# الهاتف على شبكة جوّال خلف CGNAT: لا عنوان عاماً يصله، والعنوان يتغيّر.
# فالخادم لا يستطيع مناداته أصلاً. والسحب يعمل من أي شبكة بلا عنوان
# ثابت ولا نفق ولا وسيط.
#
# ═══ التنصيب ═══
#
#   pkg update && pkg install -y termux-api jq curl
#
# وتطبيق «Termux:API» من **نفس المصدر** الذي نُصِّب منه Termux
# (F-Droid مع F-Droid، GitHub مع GitHub). اختلاف المصدر يعني توقيعاً
# مختلفاً، فلا يتحدّث البرنامجان — وأعراضه أن كل أمر termux-* يعلّق بلا
# خطأ. وهو أكثر ما يُضيّع الوقت في هذا الإعداد.
#
# ثم الإعداد في ~/.souq-sms.env :
#
#   SOUQ_SMS_URL=https://example.com
#   SOUQ_SMS_TOKEN=<من php artisan sms:token>
#   SOUQ_SMS_WORKER=pixel-4a
#   SOUQ_SMS_POLL=5          # ثواني بين نداءين
#   SOUQ_SMS_SIM=            # فارغ = الافتراضية، أو 0 / 1 لهاتف ببطاقتين
#
# ثم:
#
#   chmod +x souq-sms-worker.sh
#   ./souq-sms-worker.sh
#
# ═══ الهاتف ينام، وهذا أكبر عطل عملي ═══
#
# أندرويد يوقف عمليات الخلفية بلا إنذار. فبلا الخطوات الثلاث التالية
# يتوقف العامل بعد دقائق ولا يصل أي رمز — والخادم لا يرى شيئاً إلا
# رسائل تعود إلى الطابور بعد انتهاء مهلة السحب:
#
#   termux-wake-lock                     # قبل التشغيل
#   إعدادات > التطبيقات > Termux > البطارية > غير مُقيَّد
#   وثبّت إشعار Termux (Acquire wakelock)
#
# ═══ حدّ المشغّل ═══
#
# أندرويد يحدّ الرسائل الصادرة من تطبيق واحد (عشرات في نصف ساعة)، والمشغّل
# يحدّ أكثر ويحظر ما يبدو بثّاً جماعياً. هذا الإعداد جسرٌ حتى اتفاقية
# رسائل حقيقية (A2P) لا بديلٌ دائم عنها.
#

set -uo pipefail

CONFIG="${SOUQ_SMS_CONFIG:-$HOME/.souq-sms.env}"

if [ -f "$CONFIG" ]; then
    # shellcheck disable=SC1090
    . "$CONFIG"
fi

: "${SOUQ_SMS_URL:?ضع SOUQ_SMS_URL في $CONFIG}"
: "${SOUQ_SMS_TOKEN:?ضع SOUQ_SMS_TOKEN في $CONFIG}"
SOUQ_SMS_WORKER="${SOUQ_SMS_WORKER:-$(getprop ro.product.model 2>/dev/null || echo termux)}"
SOUQ_SMS_POLL="${SOUQ_SMS_POLL:-5}"
SOUQ_SMS_SIM="${SOUQ_SMS_SIM:-}"

API="${SOUQ_SMS_URL%/}/api/v1/sms"

log() {
    printf '%s  %s\n' "$(date '+%H:%M:%S')" "$*"
}

# نداء واحد إلى الخادم. يطبع الجسد، ويُرجع 1 عند فشل الشبكة أو حالة
# غير 2xx — التفريق مهم: فشل الشبكة يُعاد، وخطأ 401 لا فائدة من إعادته.
call() {
    local method="$1" path="$2" body="${3:-}"
    local response status
    local -a args

    # مصفوفة لا سلسلة: نصّ الرسالة عربي فيه مسافات، و`${body:+-d $body}`
    # بلا اقتباس يُقطّعه إلى وسائط — فيصل إلى curl جزءٌ منه ويُرفض الطلب
    # بـ400 بلا سبب مفهوم
    args=(
        -sS --max-time 25 -w '\n%{http_code}'
        -X "$method" "$API$path"
        -H "Authorization: Bearer $SOUQ_SMS_TOKEN"
        -H 'Content-Type: application/json'
        -H 'Accept: application/json'
    )

    [ -n "$body" ] && args+=(--data-binary "$body")

    response=$(curl "${args[@]}" 2>/dev/null)

    status="${response##*$'\n'}"
    body="${response%$'\n'*}"

    if [ "$status" = "401" ]; then
        log "✗ السرّ مرفوض (401). تأكد أن SOUQ_SMS_TOKEN يطابق SMS_WORKER_TOKEN على الخادم."
        return 2
    fi

    if [ -z "$status" ] || [ "${status:0:1}" != "2" ]; then
        log "✗ الخادم ردّ ${status:-بلا رد}"
        return 1
    fi

    printf '%s' "$body"
}

send_one() {
    local to="$1" text="$2"

    if [ -n "$SOUQ_SMS_SIM" ]; then
        termux-sms-send -s "$SOUQ_SMS_SIM" -n "$to" "$text" 2>&1
    else
        termux-sms-send -n "$to" "$text" 2>&1
    fi
}

tick() {
    local claim messages token results one id to body status error rc

    claim=$(call POST /claim "{\"worker\":\"$SOUQ_SMS_WORKER\"}") || return $?

    token=$(printf '%s' "$claim" | jq -r '.claim_token // empty')

    # لا رسائل: claim_token فارغ. وهي الحالة الغالبة — الطابور فارغ
    # معظم الوقت، والنداء رخيص
    [ -z "$token" ] && return 0

    messages=$(printf '%s' "$claim" | jq -c '.messages[]')
    results='[]'

    while IFS= read -r one; do
        [ -z "$one" ] && continue

        id=$(printf '%s' "$one" | jq -r '.id')
        to=$(printf '%s' "$one" | jq -r '.to')
        body=$(printf '%s' "$one" | jq -r '.body')

        error=$(send_one "$to" "$body")
        rc=$?

        if [ $rc -eq 0 ]; then
            status='sent'
            error=''
            log "✓ #$id → $to"
        else
            status='failed'
            log "✗ #$id → $to — ${error:-رمز خروج $rc}"
        fi

        results=$(jq -c \
            --argjson id "$id" \
            --arg status "$status" \
            --arg error "$error" \
            '. + [{id: $id, status: $status} + (if $error == "" then {} else {error: $error} end)]' \
            <<<"$results")
    done <<<"$messages"

    # التأكيد في نداء واحد للدفعة كلها: خمسة نداءات على شبكة متقطّعة
    # يعني خمس فرص لانقطاعٍ يترك سحبةً بلا تأكيد
    call POST /confirm "$(jq -nc --arg t "$token" --argjson r "$results" \
        '{claim_token: $t, results: $r}')" >/dev/null || {
        log "✗ تعذّر تأكيد الدفعة — ستعود رسائلها إلى الطابور بعد انتهاء المهلة"
        return 1
    }

    return 0
}

log "بدأ العامل: $SOUQ_SMS_WORKER → $API"

# قفل الاستيقاظ من داخل السكربت أيضاً: نسيانه هو العطل الأول، وتذكيرٌ
# في تعليق لا يُقرأ بعد الأسبوع الأول
command -v termux-wake-lock >/dev/null 2>&1 && termux-wake-lock
trap 'command -v termux-wake-unlock >/dev/null 2>&1 && termux-wake-unlock; log "توقف العامل."; exit 0' INT TERM

backoff="$SOUQ_SMS_POLL"

while true; do
    if tick; then
        # النجاح يعيد الفاصل إلى أصله
        backoff="$SOUQ_SMS_POLL"
    else
        rc=$?

        # 401 لا يُعاد بسرعة: السرّ خاطئ، والنداء كل خمس ثوانٍ يملأ
        # سجلّ الخادم بلا أن يُصلح شيئاً
        if [ $rc -eq 2 ]; then
            backoff=60
        else
            # تراجع تدريجي حتى دقيقتين: الشبكة تنقطع في اليمن لساعات،
            # ونداءٌ كل خمس ثوانٍ يستهلك البطارية بلا فائدة
            backoff=$(( backoff * 2 ))
            [ "$backoff" -gt 120 ] && backoff=120
        fi

        log "…إعادة المحاولة بعد ${backoff}ث"
    fi

    sleep "$backoff"
done

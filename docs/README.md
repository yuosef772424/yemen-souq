# توثيق المشروع

خريطة ما هو موثَّق وأين. الجذر [`README.md`](../README.md) يعرّف بالمشروع؛ وهذا المجلد
يحمل التفصيل.

## ابدأ من هنا

| الملف | يجيب عن |
|---|---|
| [`overview.md`](overview.md) | ما هو النظام؟ ما وحداته وماذا يعمل منها فعلاً؟ |
| [`build-log.md`](build-log.md) | كيف وصل إلى هنا؟ ما القرارات وما اكتُشف أثناءها؟ |
| [`completion-roadmap.md`](completion-roadmap.md) | ما الناقص، وبأي ترتيب يُبنى، وما دُرس ورُفض؟ |

**اقرأ `overview.md` لتفهم النظام، و`build-log.md` لتفهم لماذا هو هكذا.** الأول يصف
الحاضر، والثاني يصف الطريق — وأكثر ما يضيع بين الجلسات هو الثاني.

## المرجع

| الملف | الموضوع |
|---|---|
| [`architecture/modules.md`](architecture/modules.md) | عقد الوحدات: الاكتشاف التلقائي واتجاه الاعتماد وما يحرسه |
| [`architecture/design-tokens.md`](architecture/design-tokens.md) | الهوية اللونية: أين تعيش، لماذا هذه القيم، وكيف تُغيَّر بأمان |
| [`architecture/filters.md`](architecture/filters.md) | الفلاتر والترتيب: من طلب HTTP إلى استعلام، وفلترة الخصائص |
| [`architecture/api.md`](architecture/api.md) | عقد الـAPI: المصادقة بالرمز، شكل الاستجابة، الحدود، نقاط النهاية |
| [`architecture/sms-outbox.md`](architecture/sms-outbox.md) | طابور الرسائل: السحب الذرّي، الحراسة، عامل تيرمكس، وكيف تُحذف الوحدة |
| [`architecture/schema.md`](architecture/schema.md) | كل جدول وكل حقل: لماذا يوجد — وتحليل ما يُدمَج وما لا يُدمَج |
| [`architecture/removed-schema.md`](architecture/removed-schema.md) | ما حُذف من السكيمة، ولماذا، وتعريفه كاملاً لإعادته |
| [`architecture/deployment.md`](architecture/deployment.md) | النشر: الخدمات الثلاث، مصائد البناء، وقرص الصور |
| [`data-import/amazon-dataset-compatibility.md`](data-import/amazon-dataset-compatibility.md) | فحص توافق داتاسيت Amazon مع السكيمة |

## القاعدة

**كل تطور كبير يُضاف مدخلاً في [`build-log.md`](build-log.md) وقت حدوثه.**

وما كان قراراً معمارياً دائماً — لا حدثاً في الزمن — يُوثَّق ملفاً مستقلاً تحت
`architecture/` ويُشار إليه من السجلّ.

والتوثيق الذي لا يحرسه شيء يتقادم. لذلك ما يمكن تحويله إلى اختبار يُحوَّل: سجلّ السكيمة
المحذوفة يحرسه `RemovedSchemaTest`، وعقد الوحدات يحرسه `ModuleContractTest`.

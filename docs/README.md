# توثيق المشروع

خريطة ما هو موثَّق وأين. الجذر [`README.md`](../README.md) يعرّف بالمشروع؛ وهذا المجلد
يحمل التفصيل.

## ابدأ من هنا

| الملف | يجيب عن |
|---|---|
| [`overview.md`](overview.md) | ما هو النظام؟ ما وحداته وماذا يعمل منها فعلاً؟ |
| [`build-log.md`](build-log.md) | كيف وصل إلى هنا؟ ما القرارات وما اكتُشف أثناءها؟ |

**اقرأ `overview.md` لتفهم النظام، و`build-log.md` لتفهم لماذا هو هكذا.** الأول يصف
الحاضر، والثاني يصف الطريق — وأكثر ما يضيع بين الجلسات هو الثاني.

## المرجع

| الملف | الموضوع |
|---|---|
| [`architecture/modules.md`](architecture/modules.md) | عقد الوحدات: الاكتشاف التلقائي واتجاه الاعتماد وما يحرسه |
| [`architecture/api.md`](architecture/api.md) | عقد الـAPI: المصادقة بالرمز، شكل الاستجابة، الحدود، نقاط النهاية |
| [`architecture/sms-outbox.md`](architecture/sms-outbox.md) | طابور الرسائل: السحب الذرّي، الحراسة، عامل تيرمكس، وكيف تُحذف الوحدة |
| [`architecture/deferred-schema.md`](architecture/deferred-schema.md) | تسعة جداول بلا كود: لماذا بقيت ومتى تُوصَل |
| [`data-import/amazon-dataset-compatibility.md`](data-import/amazon-dataset-compatibility.md) | فحص توافق داتاسيت Amazon مع السكيمة |

## القاعدة

**كل تطور كبير يُضاف مدخلاً في [`build-log.md`](build-log.md) وقت حدوثه.**

وما كان قراراً معمارياً دائماً — لا حدثاً في الزمن — يُوثَّق ملفاً مستقلاً تحت
`architecture/` ويُشار إليه من السجلّ.

والتوثيق الذي لا يحرسه شيء يتقادم. لذلك ما يمكن تحويله إلى اختبار يُحوَّل: سجلّ السكيمة
المؤجَّلة يحرسه `DeferredSchemaTest`، وعقد الوحدات يحرسه `ModuleContractTest`.

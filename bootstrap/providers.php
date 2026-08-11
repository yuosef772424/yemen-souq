<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

/*
| موفّرات الوحدات غير مذكورة هنا بقصد: ModuleServiceProvider يمسح
| app/Modules ويسجّل موفّر كل وحدة من مجلد Providers الخاص بها.
| إضافة وحدة جديدة لا تمرّ بهذا الملف.
*/

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
];

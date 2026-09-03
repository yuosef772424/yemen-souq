<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
        | جذر قابل للضبط — وهو ما يمنع فقدان صور الإعلانات عند كل نشر.
        |
        | نظام ملفات الحاوية مؤقّت: يُبنى من الصورة عند الإقلاع ويختفي عند
        | إعادة النشر. فصورةٌ رفعها معلن أمس تختفي اليوم بلا أثر في أي
        | سجلّ — الصفّ باقٍ في `listing_images` والملف ذهب، فتظهر البطاقة
        | بمربّع فارغ.
        |
        | والعلاج قرصٌ دائم (Volume) يُركَّب على مسار خارج الصورة، ويُشار
        | إليه بهذا المتغيّر. والقيمة الافتراضية تُبقي التطوير المحلي كما
        | هو تماماً — لا قرص ولا إعداد.
        |
        | انظر docs/architecture/deployment.md لخطوات التركيب.
        */
        'public' => [
            'driver' => 'local',
            'root' => env('FILESYSTEM_PUBLIC_ROOT', storage_path('app/public')),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        // نفس مصدر الجذر أعلاه لا `storage_path` ثابتاً: الرابط الذي يخدم
        // الصور يجب أن يشير حيث تُكتب فعلاً — وقيمتان منفصلتان لمسار واحد
        // تفترقان عند أول نشر، فتُكتب الصور في القرص وتُطلب من مجلد فارغ
        public_path('storage') => env('FILESYSTEM_PUBLIC_ROOT', storage_path('app/public')),
    ],

];

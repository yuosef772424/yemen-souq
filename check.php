<?php

/**
 * سكربت لمرة واحدة: إصلاحات مراجعة الكود.
 *
 *   php scaffold-fixes.php
 *
 * يطبّق ستة إصلاحات ويطبع تقريراً بما نجح وما يحتاج تدخلاً يدوياً.
 */
$root = __DIR__;
$done = [];
$manual = [];

/** كتابة بلا BOM، مع الحفاظ على نهايات الأسطر الموحّدة */
function put(string $path, string $contents): bool
{
    return file_put_contents($path, str_replace("\r\n", "\n", $contents)) !== false;
}

function read(string $path): ?string
{
    return is_file($path) ? file_get_contents($path) : null;
}

// ═════════════════════════════════════════════════════════════
//  1) حذف ملف الاختبار المكرر داخل app/
// ═════════════════════════════════════════════════════════════
$stray = $root.'/app/Modules/Shared/Services/ExchangeRateServiceTest.php';

if (is_file($stray)) {
    unlink($stray) ? $done[] = 'حُذف ملف الاختبار المكرر من app/Modules/Shared/Services'
        : $manual[] = 'تعذّر حذف '.$stray;
} else {
    $done[] = 'لا يوجد ملف اختبار مكرر';
}

// ═════════════════════════════════════════════════════════════
//  2) hide_phone: يُتحقَّق منه ويُحفظ فعلاً
// ═════════════════════════════════════════════════════════════
$requestPath = $root.'/app/Modules/Catalog/Http/Requests/StoreListingRequest.php';
$request = read($requestPath);

if ($request === null) {
    $manual[] = 'StoreListingRequest غير موجود';
} elseif (str_contains($request, "'hide_phone'")) {
    $done[] = 'hide_phone مُتحقَّق منه مسبقاً';
} else {
    $anchor = "'contact_phone' => ['required', 'string', 'regex:/^\\+?[0-9\\s\\-]{9,20}$/'],";

    if (str_contains($request, $anchor)) {
        $request = str_replace(
            $anchor,
            $anchor."\n            // بلا هذه القاعدة يستبعده safe() فلا يُحفظ أبداً،\n"
                ."            // ويتجاهل النظام طلب المعلن بإخفاء رقمه\n"
                ."            'hide_phone' => ['nullable', 'boolean'],",
            $request
        );

        // خانة غير مُحدَّدة لا تُرسَل إطلاقاً — الدمج يجعلها false صريحة
        $request = str_replace(
            "        if (is_string(\$phone = \$this->input('contact_phone'))) {",
            "        \$this->merge(['hide_phone' => \$this->boolean('hide_phone')]);\n\n"
                ."        if (is_string(\$phone = \$this->input('contact_phone'))) {",
            $request
        );

        put($requestPath, $request)
            ? $done[] = 'hide_phone صار يُتحقَّق منه ويُحفظ'
            : $manual[] = 'تعذّرت كتابة StoreListingRequest';
    } else {
        $manual[] = 'لم تُطابق قاعدة contact_phone — أضف يدوياً في rules(): '
            ."'hide_phone' => ['nullable', 'boolean'],";
    }
}

// ═════════════════════════════════════════════════════════════
//  3) HTML مكسور في صندوق التواصل
// ═════════════════════════════════════════════════════════════
$showPath = $root.'/app/Modules/Catalog/Resources/Views/show.blade.php';
$show = read($showPath);

if ($show === null) {
    $manual[] = 'show.blade.php غير موجود';
} else {
    $broken = ')"class=';

    if (str_contains($show, $broken)) {
        put($showPath, str_replace($broken, ')" class=', $show))
            ? $done[] = 'أُصلحت المسافة الناقصة قبل class في صندوق التواصل'
            : $manual[] = 'تعذّرت كتابة show.blade.php';
    } else {
        $done[] = 'صندوق التواصل سليم';
    }
}

// ═════════════════════════════════════════════════════════════
//  4) تسجيل حدث إنشاء الإعلان + حذف الحالات الميتة من enum
// ═════════════════════════════════════════════════════════════
$createPath = $root.'/app/Modules/Catalog/Http/Controllers/ListingCreateController.php';
$create = read($createPath);

if ($create === null) {
    $manual[] = 'ListingCreateController غير موجود';
} elseif (str_contains($create, 'EventName::ListingCreated')) {
    $done[] = 'حدث إنشاء الإعلان مُسجَّل مسبقاً';
} else {
    // الاستيرادات
    if (! str_contains($create, 'use App\Modules\Analytics\Events\EventName;')) {
        $create = str_replace(
            'use App\Modules\Catalog\Http\Requests\StoreListingRequest;',
            "use App\Modules\Analytics\Events\EventName;\n"
                ."use App\Modules\Analytics\Services\EventRecorder;\n"
                .'use App\Modules\Catalog\Http\Requests\StoreListingRequest;',
            $create
        );
    }

    // الاعتماد
    $create = str_replace(
        "        private readonly ListingImageService \$images,\n    ) {",
        "        private readonly ListingImageService \$images,\n"
            ."        private readonly EventRecorder \$events,\n    ) {",
        $create
    );

    // التسجيل بعد رفع الصور
    $anchor = "\$result = \$this->images->attach(\$listing, \$request->file('images', []));";

    if (str_contains($create, $anchor)) {
        $create = str_replace(
            $anchor,
            $anchor."\n\n"
                ."        // بلا هذا الحدث يبقى listings_created صفراً دائماً\n"
                ."        // في funnel_daily، فيبدو مسار التحويل ناقصاً\n"
                ."        \$this->events->record(EventName::ListingCreated, 'listing', \$listing->id, [\n"
                ."            'category_id' => \$listing->category_id,\n"
                ."            'currency' => \$listing->currency_code,\n"
                ."            'images' => \$result['stored'],\n"
                .'        ]);',
            $create
        );

        put($createPath, $create)
            ? $done[] = 'صار حدث إنشاء الإعلان يُسجَّل'
            : $manual[] = 'تعذّرت كتابة ListingCreateController';
    } else {
        $manual[] = 'لم يُطابق موضع attach() — أضف تسجيل ListingCreated يدوياً';
    }
}

// حذف الحالتين غير المستخدمتين: حالة معرَّفة وميتة توحي بميزة غير موجودة
$enumPath = $root.'/app/Modules/Analytics/Events/EventName.php';
$enum = read($enumPath);

if ($enum !== null) {
    $before = $enum;

    $enum = preg_replace(
        '/\s*case FilterRemoved = \'filter_removed\';/',
        '',
        $enum
    );

    $enum = preg_replace(
        '/\s*case FormAbandoned = \'form_abandoned\';/',
        '',
        $enum
    );

    if ($enum !== $before) {
        put($enumPath, $enum);
        $done[] = 'حُذفت حالتا FilterRemoved و FormAbandoned (معرَّفتان وغير مستخدمتين)';
        $manual[] = 'بعد الحذف: أزل من RollupCommand أي إشارة إلى filter_removed و form_abandoned'
            .' — انظر البند 6 أدناه (يفعله السكربت تلقائياً)';
    } else {
        $done[] = 'enum الأحداث نظيف';
    }
}

// ═════════════════════════════════════════════════════════════
//  5) $guarded المضلّل في Listing
// ═════════════════════════════════════════════════════════════
$listingPath = $root.'/app/Modules/Catalog/Models/Listing.php';
$listing = read($listingPath);

if ($listing === null) {
    $manual[] = 'Listing.php غير موجود';
} elseif (! str_contains($listing, 'protected $guarded')) {
    $done[] = 'Listing بلا $guarded مضلّل';
} else {
    $pattern = '/\s*\/\*\*\s*\n\s*\* أعمدة محسوبة.*?protected \$guarded = \[.*?\];/s';

    $replacement = "\n"
        ."    /**\n"
        ."     * الأعمدة المحسوبة — title_normalized و description_normalized\n"
        ."     * و price_in_base و rate_used و rate_date — مستثناة من \$fillable\n"
        ."     * عن قصد. يملؤها ListingObserver وحده، فلا يستطيع طلب HTTP\n"
        ."     * تمرير قيمة مزيّفة لها.\n"
        ."     *\n"
        ."     * لا نستخدم \$guarded: لارافيل يتجاهله تماماً حين يكون \$fillable\n"
        ."     * غير فارغ، فوجوده معاً يوحي بحماية مزدوجة غير قائمة.\n"
        .'     */';

    $updated = preg_replace($pattern, $replacement, $listing, 1);

    if ($updated !== null && $updated !== $listing) {
        put($listingPath, $updated)
            ? $done[] = 'حُذف $guarded المضلّل مع توثيق سبب الاستثناء'
            : $manual[] = 'تعذّرت كتابة Listing.php';
    } else {
        $manual[] = 'لم يُطابق بلوك $guarded — احذفه يدوياً من Listing.php';
    }
}

// ═════════════════════════════════════════════════════════════
//  6) RollupCommand: ذاكرة، وتعليق المفضلة، وإزالة الأحداث المحذوفة
// ═════════════════════════════════════════════════════════════
$rollupPath = $root.'/app/Modules/Analytics/Console/RollupCommand.php';
$rollup = read($rollupPath);

if ($rollup === null) {
    $manual[] = 'RollupCommand غير موجود';
} else {
    $changed = false;

    // 6أ) استبدال pluck على كل الجدول بفحص وجود في الاستعلام نفسه
    if (str_contains($rollup, "DB::table('listings')->pluck('id')->all()")) {
        $rollup = preg_replace(
            "/\s*\\\$existing = DB::table\('listings'\)->pluck\('id'\)->all\(\);\s*\n\s*\\\$valid = array_flip\(\\\$existing\);\s*\n/",
            "\n",
            $rollup
        );

        // الفلترة تنتقل إلى الاستعلام: joinSub غير لازم، whereExists كافٍ
        $rollup = str_replace(
            "            ->groupBy('entity_id')\n            ->get();",
            "            ->whereExists(fn (\$q) => \$q->select(DB::raw(1))\n"
                ."                ->from('listings')\n"
                ."                ->whereColumn('listings.id', 'user_events.entity_id'))\n"
                ."            ->groupBy('entity_id')\n            ->get();",
            $rollup
        );

        // إزالة فحص الصحة داخل الحلقة — صار في الاستعلام
        $rollup = preg_replace(
            "/\s*\/\/ الإعلان قد يكون حُذف بعد تسجيل الحدث\s*\n\s*if \(! isset\(\\\$valid\[\\\$row->listing_id\]\)\) \{\s*\n\s*continue;\s*\n\s*\}\s*\n/",
            "\n",
            $rollup
        );

        $changed = true;
        $done[] = 'التجميع لم يعد يحمّل كل معرّفات الإعلانات في الذاكرة';
    }

    // 6ب) توضيح أن صفر المفضلة مقصود
    if (str_contains($rollup, "'favorites' => 0,")) {
        $rollup = str_replace(
            "'favorites' => 0,",
            "// المفضلة غير مبنية بعد — صفر مقصود لا خطأ حساب\n                    'favorites' => 0,",
            $rollup
        );

        $changed = true;
        $done[] = 'وُثّق أن صفر المفضلة مقصود';
    }

    // 6ج) الفلاتر: الإلغاء السريع غير مُتتبَّع، فلا نستعلم عن حدث غير موجود
    if (str_contains($rollup, "'filter_removed'")) {
        $rollup = preg_replace(
            '/\s*\/\/ الإلغاء السريع.*?->pluck\(\'n\', \'filter_key\'\);/s',
            "\n        // الإلغاء السريع للفلتر غير مُتتبَّع بعد: يحتاج قياساً في المتصفح\n"
                ."        // (وقت التطبيق ثم حدث عند الإلغاء). العمود يبقى صفراً حتى ذلك الحين.\n"
                .'        $removedFast = collect();',
            $rollup,
            1
        );

        $changed = true;
        $done[] = 'أُزيل استعلام filter_removed غير المستخدم';
    }

    // 6د) مسار التحويل: عمودان لحدثين محذوفين
    if (str_contains($rollup, "SUM(event_name = 'form_abandoned')")) {
        $rollup = str_replace(
            ",\n                SUM(event_name = 'form_abandoned') as forms_abandoned",
            '',
            $rollup
        );

        $rollup = str_replace(
            "'forms_abandoned' => (int) \$counts->forms_abandoned,",
            "// النماذج المهجورة غير مُتتبَّعة بعد\n            'forms_abandoned' => 0,",
            $rollup
        );

        $changed = true;
        $done[] = 'أُزيل عمود forms_abandoned من الاستعلام';
    }

    // 6هـ) الافتراضي يبدأ من اليوم — أبده في الاستخدام اليدوي
    if (str_contains($rollup, 'Carbon::yesterday()')) {
        $rollup = str_replace(
            ': Carbon::yesterday();',
            ': Carbon::today();',
            $rollup
        );

        $rollup = str_replace(
            '{--days=1 : عدد الأيام السابقة لإعادة تجميعها}',
            '{--days=1 : عدد الأيام بدءاً من اليوم رجوعاً للخلف}',
            $rollup
        );

        $changed = true;
        $done[] = 'الافتراضي صار اليوم لا الأمس (مرّر --date في الجدولة صراحةً)';
    }

    if ($changed) {
        put($rollupPath, $rollup) ?: $manual[] = 'تعذّرت كتابة RollupCommand';
    } else {
        $done[] = 'RollupCommand معدَّل مسبقاً';
    }
}

// ═════════════════════════════════════════════════════════════
//  7) .gitattributes لتوحيد نهايات الأسطر
// ═════════════════════════════════════════════════════════════
$gitattributes = $root.'/.gitattributes';

if (! is_file($gitattributes) || ! str_contains((string) read($gitattributes), 'text=auto')) {
    $contents = <<<'GA'
* text=auto eol=lf

*.png binary
*.jpg binary
*.webp binary
*.svg text eol=lf
GA;

    put($gitattributes, $contents)
        ? $done[] = 'أُنشئ .gitattributes لتوحيد نهايات الأسطر'
        : $manual[] = 'تعذّر إنشاء .gitattributes';
} else {
    $done[] = '.gitattributes موجود';
}

// ═════════════════════════════════════════════════════════════
//  التقرير
// ═════════════════════════════════════════════════════════════

echo "\n══════════ تم ══════════\n";
foreach ($done as $item) {
    echo "  ✓ {$item}\n";
}

if ($manual !== []) {
    echo "\n══════════ يحتاج تدخلاً يدوياً ══════════\n";
    foreach ($manual as $item) {
        echo "  ! {$item}\n";
    }
}

echo "\nالخطوات المتبقية:\n";
echo "  1. composer dump-autoload\n";
echo "  2. ./vendor/bin/pint            (ثبّته: composer require laravel/pint --dev)\n";
echo "  3. php artisan optimize:clear\n";
echo "  4. php artisan test\n";
echo "  5. git add --renormalize .\n";
echo "  6. راجع الجدولة في routes/console.php: مرّر --date صراحةً\n";
echo "     Schedule::command('analytics:rollup', ['--date' => now()->subDay()->toDateString()])->dailyAt('01:00');\n";
echo "  7. Remove-Item scaffold-fixes.php\n";

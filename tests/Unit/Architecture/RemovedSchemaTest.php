<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * السكيمة لا تَعِد بما لا ينفّذه الكود.
 *
 * القاعدة السابقة كانت أضعف: «الجدول الفارغ يبقى ويُعلَن تأجيله في
 * سجلّ». وسقطت لأن الوعد الكاذب لا يقع في ملف التوثيق بل في القاعدة
 * نفسها — من يفتح المخطط يقرأ `auctions` ولا يقرأ `docs/`.
 *
 * فصارت القاعدة: **كل جدول يشير إليه كود التطبيق، وما لا يشير إليه شيء
 * يُحذف ويُحفَظ تعريفه** في docs/architecture/removed-schema.md. وهذا
 * الاختبار يحرس الطرفين: لا جدولَ بلا كود، ولا سجلَّ متقادم.
 */
class RemovedSchemaTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private const RECORD = '/docs/architecture/removed-schema.md';

    /** جداول لارافل نفسه — خارج الحساب */
    private const FRAMEWORK_TABLES = [
        'users', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    #[Test]
    public function every_table_a_migration_creates_is_referenced_by_application_code(): void
    {
        $unwired = $this->unwiredTables();

        $this->assertSame([], $unwired, implode("\n", array_merge(
            ['جداول تُنشئها هجرة ولا يشير إليها سطر واحد في app/:'],
            array_map(static fn (string $t): string => "  - {$t}", $unwired),
            [
                '',
                'إمّا تُوصَل بكود، أو تُحذف ويُحفَظ تعريفها في',
                'docs/architecture/removed-schema.md لتُعاد يوم تُبنى ميزتها.',
            ]
        )));
    }

    /**
     * الاتجاه الذي يمنع تقادم السجلّ: جدول محذوف عادت هجرةٌ تُنشئه.
     */
    #[Test]
    public function the_record_lists_no_table_that_a_migration_creates_again(): void
    {
        $resurrected = array_values(array_intersect($this->recordedTables(), $this->allTables()));

        $this->assertSame([], $resurrected, implode("\n", array_merge(
            ['جداول مذكورة كمحذوفة وقد أنشأتها هجرة من جديد:'],
            array_map(static fn (string $t): string => "  - {$t}", $resurrected),
            ['', 'تُشطب من docs/architecture/removed-schema.md — الميزة عادت.']
        )));
    }

    /**
     * أسماء الجداول في العمود الأول من جدول السجلّ، بين علامتَي `.
     *
     * والأعمدة المحذوفة (`listings.sale_type`) لا تُلتقط بقصد: النقطة
     * خارج الصنف، فيقتصر الحصاد على أسماء الجداول.
     *
     * @return list<string>
     */
    private function recordedTables(): array
    {
        $record = @file_get_contents(self::ROOT.self::RECORD);

        $this->assertNotFalse($record, 'سجلّ السكيمة المحذوفة غير موجود.');

        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $record, $matches);

        $this->assertNotEmpty($matches[1], 'السجلّ بلا جدول واحد — أُفرِغ أو تغيّر شكله.');

        return $matches[1];
    }

    /** @return list<string> */
    private function unwiredTables(): array
    {
        return array_values(array_diff($this->allTables(), $this->wiredTables()));
    }

    /**
     * جدول «موصول» = اسمه يرد في ملف php خارج الهجرات داخل app/.
     *
     * الاختبارات مستثناة عمداً: جدول لا يمسّه غير اختباره ليس منفَّذاً.
     *
     * @return list<string>
     */
    private function wiredTables(): array
    {
        $sources = [];

        foreach ($this->phpFiles(self::ROOT.'/app') as $path) {
            if (! $this->isMigration($path)) {
                $sources[] = file_get_contents($path);
            }
        }

        $wired = [];

        foreach ($this->allTables() as $table) {
            foreach ($sources as $source) {
                if (preg_match('/\b'.preg_quote($table, '/').'\b/', $source) === 1) {
                    $wired[] = $table;

                    break;
                }
            }
        }

        return $wired;
    }

    /** @return list<string> */
    private function allTables(): array
    {
        $paths = array_merge(
            $this->phpFiles(self::ROOT.'/app'),
            $this->phpFiles(self::ROOT.'/database/migrations')
        );

        $tables = [];

        foreach ($paths as $path) {
            if (! $this->isMigration($path)) {
                continue;
            }

            preg_match_all("/Schema::create\('([a-z_]+)'/", file_get_contents($path), $matches);

            foreach ($matches[1] as $table) {
                if (! in_array($table, self::FRAMEWORK_TABLES, true)) {
                    $tables[$table] = true;
                }
            }
        }

        $names = array_keys($tables);
        sort($names);

        return $names;
    }

    private function isMigration(string $path): bool
    {
        return str_contains($path, '/Migrations/') || str_contains($path, '/database/migrations/');
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        return $files;
    }
}

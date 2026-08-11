<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * السكيمة لا تَعِد بما لا ينفّذه الكود — أو تُعلن التأجيل صراحةً.
 *
 * تسعة جداول في المشروع لا يشير إليها كود التطبيق. المشكلة ليست وجودها
 * بل صمتها: جدول فارغ لا يميّزه شيء عن جدول معطَّل، فيُقرأ auctions في
 * السكيمة ويُفترض أن المزادات تعمل.
 *
 * السجلّ في docs/architecture/deferred-schema.md هو مصدر الحقيقة، وهذا
 * الاختبار يقارنه بالواقع في اتجاهين — فلا يتقادم.
 */
class DeferredSchemaTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** جداول لارافل نفسه — خارج الحساب */
    private const FRAMEWORK_TABLES = [
        'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    #[Test]
    public function every_unwired_table_is_declared_in_the_register(): void
    {
        $undeclared = array_values(array_diff($this->unwiredTables(), $this->registeredTables()));

        $this->assertSame([], $undeclared, implode("\n", array_merge(
            ['جداول لا يشير إليها كود ولا يذكرها docs/architecture/deferred-schema.md:'],
            array_map(static fn (string $t): string => "  - {$t}", $undeclared),
            ['', 'إمّا تُوصَل بكود، أو تُضاف إلى السجلّ بسبب صريح وموعد.']
        )));
    }

    /**
     * الاتجاه الذي يمنع تقادم السجلّ: جدول صار موصولاً ولم يُشطب.
     */
    #[Test]
    public function the_register_lists_no_table_that_is_already_wired(): void
    {
        $stale = array_values(array_intersect($this->registeredTables(), $this->wiredTables()));

        $this->assertSame([], $stale, implode("\n", array_merge(
            ['جداول مذكورة كمؤجَّلة وقد صار لها كود فعلاً:'],
            array_map(static fn (string $t): string => "  - {$t}", $stale),
            ['', 'تُشطب من docs/architecture/deferred-schema.md — الميزة وُصلت.']
        )));
    }

    /**
     * أسماء الجداول في العمود الأول من جدول السجلّ، بين علامتَي `.
     *
     * @return list<string>
     */
    private function registeredTables(): array
    {
        $doc = file_get_contents(self::ROOT.'/docs/architecture/deferred-schema.md');

        $this->assertNotFalse($doc, 'سجلّ السكيمة المؤجَّلة غير موجود.');

        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $doc, $matches);

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

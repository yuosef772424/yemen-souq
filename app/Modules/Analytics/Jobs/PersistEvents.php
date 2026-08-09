<?php

namespace App\Modules\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * إدخال دفعة واحدة لكل الأحداث.
 *
 * insert() مباشر لا Eloquent: لا نحتاج موديلاً ولا أحداثاً ولا timestamps،
 * ونريد أرخص كتابة ممكنة. صفحة بحث بـ24 نتيجة تُنتج 26 حدثاً في استعلام واحد.
 */
class PersistEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly array $rows,
    ) {}

    public function handle(): void
    {
        if ($this->rows === []) {
            return;
        }

        try {
            DB::table('user_events')->insert($this->rows);
        } catch (Throwable $exception) {
            // فقدان حدث تحليلي لا يستحق إفشال أي شيء
            report($exception);
        }
    }
}

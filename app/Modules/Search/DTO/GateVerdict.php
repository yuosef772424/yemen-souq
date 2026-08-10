<?php

namespace App\Modules\Search\DTO;

/**
 * حكم البوابة على زوج مرشَّح.
 *
 * ثلاث حالات لا اثنتان: مقبول، مرفوض، وغير قابل للتحقق. الثالثة ليست
 * تفصيلاً — «لا أملك بيانات كافية للحكم» ليس «مرفوض». خلطهما يجعل كل
 * مكافئ لسلعة نادرة يُرفض إلى الأبد لأنها نادرة، فيبقى البحث ضعيفاً
 * حيث هو أضعف ما يكون.
 */
final readonly class GateVerdict
{
    /** @param array<int, string> $reasons */
    private function __construct(
        public string $outcome,
        public array $reasons,
    ) {
    }

    /** @param array<int, string> $reasons */
    public static function pass(array $reasons = []): self
    {
        return new self('pass', $reasons);
    }

    public static function reject(string $reason): self
    {
        return new self('reject', [$reason]);
    }

    public static function unverified(string $reason): self
    {
        return new self('unverified', [$reason]);
    }

    public function passed(): bool
    {
        return $this->outcome === 'pass';
    }

    public function rejected(): bool
    {
        return $this->outcome === 'reject';
    }

    public function reason(): string
    {
        return implode(' · ', $this->reasons);
    }
}
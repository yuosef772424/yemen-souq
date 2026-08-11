<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction as ActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صفّ في سجل المساءلة.
 *
 * $timestamps = false لأن الجدول يحمل created_at وحده: صفّ سجل لا
 * يُعدَّل أبداً، فعمود updated_at فيه يوهم بأن التعديل ممكن.
 */
class AdminActionRecord extends Model
{
    protected $table = 'admin_actions';

    public $timestamps = false;

    protected $fillable = ['admin_id', 'action', 'entity_type', 'entity_id', 'reason', 'meta', 'created_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function actionType(): ?ActionType
    {
        return ActionType::tryFrom($this->action);
    }

    public function label(): string
    {
        return $this->actionType()?->label() ?? $this->action;
    }
}

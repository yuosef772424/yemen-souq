<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingQuestion extends Model
{
    protected $table = 'listing_questions';

    /**
     * answer وanswered_at غائبان عن fillable بقصد: الجواب يُكتب من
     * QuestionService بعد فحص الملكية، لا من نموذج. وhidden_at للإدارة.
     */
    protected $fillable = [
        'listing_id',
        'asker_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    /** المرئي للعموم — المخفيّ إدارياً يُقصى من كل عرض */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
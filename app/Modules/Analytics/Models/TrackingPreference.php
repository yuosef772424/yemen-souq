<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingPreference extends Model
{
    protected $table = 'user_tracking_preferences';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['user_id', 'allow_analytics', 'allow_personalization', 'updated_at'];

    protected function casts(): array
    {
        return [
            'allow_analytics' => 'boolean',
            'allow_personalization' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}

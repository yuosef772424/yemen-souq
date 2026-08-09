<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingImage extends Model
{
    protected $fillable = ['listing_id', 'path', 'thumbnail_path', 'is_primary', 'order'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
    public function images()
{
    return $this->hasMany(ListingImage::class)->orderBy('order');
}

public function primaryImage()
{
    return $this->hasOne(ListingImage::class)->where('is_primary', true);
}
}
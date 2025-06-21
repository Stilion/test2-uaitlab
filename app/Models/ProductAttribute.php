<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'value',
        'filter_key'
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

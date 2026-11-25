<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMeta extends Model
{
    protected $table = 'product_metas';

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ref_parent');
    }
}

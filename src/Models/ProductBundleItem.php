<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product inside a bundle, with its price in the bundle (its own price stays
 * on the product; the difference is the bundle's discount on it).
 */
class ProductBundleItem extends Model
{
    protected $table = 'product_bundle_items';

    protected $fillable = ['bundle_id', 'product_id', 'quantity', 'price_in_bundle', 'sort'];

    protected $casts = ['price_in_bundle' => 'decimal:2', 'quantity' => 'integer'];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

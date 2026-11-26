<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One use of a discount: by whom, for what (e.g. a membership period), how much. */
class ProductDiscountRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'product_discount_redemptions';

    protected $fillable = [
        'product_discount_id',
        'product_id',
        'user_id',
        'subject_type',
        'subject_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(ProductDiscount::class, 'product_discount_id');
    }
}

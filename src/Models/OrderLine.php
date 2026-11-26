<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line of an order. Prices are copied when the order is made (per unit:
 * the product's own price, the discount, the price paid), so later price
 * changes don't change it. A bundle becomes one line per item (bundle_id).
 */
class OrderLine extends Model
{
    protected $table = 'order_lines';

    protected $fillable = [
        'uid', 'order_id', 'product_id', 'bundle_id', 'product_type', 'description', 'quantity',
        'unit_price', 'discount', 'price', 'line_total', 'valid_from', 'valid_until', 'meta', 'sort',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'meta' => 'array',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->uid ??= (string) Str::ulid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_id');
    }
}

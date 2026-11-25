<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A discount: on one product, on every product of a type, or on all of an
 * organisation's products. Applied automatically, or with a coupon code.
 *
 * @property string $kind percent | fixed
 * @property string $value
 * @property ?string $code
 */
class ProductDiscount extends Model
{
    public const KIND_PERCENT = 'percent';

    public const KIND_FIXED = 'fixed';

    protected $table = 'product_discounts';

    protected $fillable = [
        'uid',
        'organisation_id',
        'product_id',
        'product_type',
        'name',
        'kind',
        'value',
        'code',
        'valid_from',
        'valid_until',
        'max_uses',
        'max_uses_per_user',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $discount) {
            $discount->uid ??= (string) Str::ulid();
        });
        static::saving(function (self $discount) {
            // Codes are matched without regard to case or spaces.
            $discount->code = self::normaliseCode($discount->code);
        });
    }

    public static function normaliseCode(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $code));

        return $code === '' ? null : $code;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(ProductDiscountRedemption::class);
    }

    public function isCode(): bool
    {
        return $this->code !== null;
    }

    /** The amount taken off a price (never more than the price). */
    public function amountOff(string|float $price): float
    {
        $price = (float) $price;
        $off = $this->kind === self::KIND_PERCENT
            ? round($price * (float) $this->value / 100, 2)
            : (float) $this->value;

        return min($price, max(0, $off));
    }

    /** "20% off" / "₹500 off" style wording for people. */
    public function describe(string $currency = 'INR'): string
    {
        return $this->kind === self::KIND_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : $currency.' '.number_format((float) $this->value, 2).' off';
    }
}

<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * Something an organisation sells: a membership plan, an event ticket,
 * merchandise, a donation… Its price and discounts come from here.
 *
 * @property string $uid
 * @property string $name
 * @property string $type
 * @property string $price
 * @property string $currency
 */
class Product extends Model
{
    public const TYPES = ['membership', 'ticket', 'merchandise', 'donation', 'other'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'products';

    protected $fillable = [
        'uid',
        'name',
        'type',
        'description',
        'price',
        'currency',
        'sku',
        'organisation_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            $product->uid ??= (string) Str::ulid();
            $product->status ??= self::STATUS_ACTIVE;
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function metas(): HasMany
    {
        return $this->hasMany(ProductMeta::class, 'ref_parent');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(ProductDiscount::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getMetaValue(string $key): ?string
    {
        return $this->metas()->where('meta_key', $key)->value('meta_value');
    }

    public function setMetaValue(string $key, ?string $value): ProductMeta
    {
        return $this->metas()->updateOrCreate(['meta_key' => $key], ['meta_value' => $value, 'status' => 'active']);
    }
}

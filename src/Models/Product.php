<?php

namespace Iquesters\Commerce\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * Something an organisation sells: a membership, an event ticket, a bundle of
 * products, merchandise, a donation… Its price, discounts, validity, sale
 * window and stock come from here.
 *
 * @property string $uid
 * @property string $name
 * @property string $type
 * @property string $price
 * @property string $currency
 */
class Product extends Model
{
    public const TYPES = ['membership', 'ticket', 'bundle', 'merchandise', 'donation', 'other'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const KIND_VIRTUAL = 'virtual';

    public const KIND_PHYSICAL = 'physical';

    public const VISIBILITY_PUBLIC = 'public';

    /** Only admins add it (e.g. crew, invited guests). */
    public const VISIBILITY_PRIVATE = 'private';

    public const VALIDITY_NONE = 'none';

    public const VALIDITY_FIXED = 'fixed';

    public const VALIDITY_DURATION = 'duration';

    public const VALID_UNITS = ['days', 'months', 'years'];

    protected $table = 'products';

    protected $fillable = [
        'uid', 'name', 'type', 'kind', 'visibility', 'description', 'price', 'currency',
        'validity', 'valid_from', 'valid_until', 'valid_for', 'valid_unit',
        'sale_from', 'sale_until', 'track_stock', 'stock_quantity', 'stock_reserved',
        'custom_amount', 'min_amount', 'max_amount', 'ref_type', 'ref_id',
        'sku', 'organisation_id', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'sale_from' => 'datetime',
        'sale_until' => 'datetime',
        'track_stock' => 'boolean',
        'custom_amount' => 'boolean',
        'stock_quantity' => 'integer',
        'stock_reserved' => 'integer',
        'valid_for' => 'integer',
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

    /** A bundle's items (other products, each with its price in the bundle). */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id')->orderBy('sort')->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBundle(): bool
    {
        return $this->type === 'bundle';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /** Active and inside its sale window. */
    public function isOnSale(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->isActive()
            && (! $this->sale_from || $this->sale_from->lte($at))
            && (! $this->sale_until || $this->sale_until->gte($at));
    }

    /** How many can still be sold (null: no limit). */
    public function stockLeft(): ?int
    {
        return $this->track_stock ? max(0, (int) $this->stock_quantity - (int) $this->stock_reserved) : null;
    }

    /**
     * The dates a purchase made now (or paid at $at) is valid for.
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    public function validityFor(?CarbonInterface $at = null): array
    {
        $at ??= now();

        return match ($this->validity) {
            self::VALIDITY_FIXED => [$this->valid_from, $this->valid_until],
            self::VALIDITY_DURATION => $this->valid_for
                ? [$at->copy()->startOfDay(), match ($this->valid_unit) {
                    'days' => $at->copy()->addDays($this->valid_for),
                    'years' => $at->copy()->addYears($this->valid_for),
                    default => $at->copy()->addMonths($this->valid_for),
                }]
                : [null, null],
            default => [null, null],
        };
    }

    /** A donation product's setting: offered (optional amount) before every paid checkout of its organisation. */
    public const META_OFFER_WITH_EVERY_ORDER = 'offer_with_every_order';

    /**
     * What a buyer may pay when they choose the amount (e.g. a donation): at
     * least its minimum (₹1 when none is set) and at most its maximum, if any.
     *
     * @return array{min: float, max: ?float}
     */
    public function amountLimits(): array
    {
        $min = $this->min_amount !== null && (float) $this->min_amount > 0 ? (float) $this->min_amount : 1.0;
        $max = $this->max_amount !== null && (float) $this->max_amount > 0 ? max($min, (float) $this->max_amount) : null;

        return ['min' => $min, 'max' => $max];
    }

    /** The limits in words, e.g. "₹1 or more", "₹100 to ₹5,000". */
    public function amountLimitsText(): string
    {
        ['min' => $min, 'max' => $max] = $this->amountLimits();
        $symbol = strtoupper((string) ($this->currency ?: 'INR')) === 'INR' ? '₹' : strtoupper((string) $this->currency).' ';
        $money = fn (float $v) => $symbol.rtrim(rtrim(number_format($v, 2), '0'), '.');

        return $max !== null ? $money($min).' to '.$money($max) : $money($min).' or more';
    }

    public function offeredWithEveryOrder(): bool
    {
        return $this->type === 'donation' && $this->getMetaValue(self::META_OFFER_WITH_EVERY_ORDER) === '1';
    }

    /**
     * The organisation's donation to offer at every paid checkout (its setting
     * "Offer with every paid order"), if it has one active and on sale.
     */
    public static function donationForEveryOrder(int $organisationId): ?self
    {
        return self::query()
            ->where('organisation_id', $organisationId)
            ->where('type', 'donation')
            ->where('status', self::STATUS_ACTIVE)
            ->where('visibility', self::VISIBILITY_PUBLIC)
            ->whereHas('metas', fn ($q) => $q->where('meta_key', self::META_OFFER_WITH_EVERY_ORDER)->where('meta_value', '1'))
            ->orderBy('id')
            ->get()
            ->first(fn (self $p) => $p->isOnSale());
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

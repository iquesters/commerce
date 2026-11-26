<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * An order: what someone buys from an organisation (lines), its total and
 * where its payment is. Paying it is the payment module's job; when it
 * becomes paid, commerce fires OrderPaid and other modules react.
 */
class Order extends Model
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    /** Proof of payment sent; an admin checks it. */
    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_PAID = 'paid';

    /** The payment wasn't accepted; the buyer may send proof again. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_AWAITING_PAYMENT, self::STATUS_UNDER_REVIEW, self::STATUS_PAID, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    public const LABELS = [
        self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
        self::STATUS_UNDER_REVIEW => 'Payment under review',
        self::STATUS_PAID => 'Paid',
        self::STATUS_REJECTED => 'Payment rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Allowed moves. */
    public const MOVES = [
        self::STATUS_AWAITING_PAYMENT => [self::STATUS_UNDER_REVIEW, self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_PAID, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_UNDER_REVIEW, self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'orders';

    protected $fillable = [
        'uid', 'organisation_id', 'reference', 'user_id', 'buyer_name', 'buyer_email', 'buyer_phone',
        'status', 'subtotal', 'discount_total', 'total', 'currency', 'source', 'ref_type', 'ref_id',
        'notes', 'paid_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->uid ??= (string) Str::ulid();
            $order->status ??= self::STATUS_AWAITING_PAYMENT;
        });
    }

    /**
     * The lines for showing to people: the same item at the same price once,
     * with its count ("2 × Membership 2027"). The order keeps a line per person.
     *
     * @return \Illuminate\Support\Collection<int, array{description: string, quantity: int, total: float, full: float}>
     */
    public function groupedLines(): \Illuminate\Support\Collection
    {
        return $this->lines
            ->groupBy(fn ($l) => implode('|', [$l->product_id, $l->description, (float) $l->unit_price, round((float) $l->line_total / max(1, (int) $l->quantity), 2)]))
            ->map(fn ($lines) => [
                'description' => (string) $lines->first()->description,
                'quantity' => (int) $lines->sum('quantity'),
                'total' => round((float) $lines->sum(fn ($l) => (float) $l->line_total), 2),
                // Before any discount (e.g. a membership included in a bundle: ₹600 → ₹0).
                'full' => round((float) $lines->sum(fn ($l) => (float) $l->unit_price * (int) $l->quantity), 2),
            ])
            ->values();
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('sort')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::MOVES[$this->status] ?? [], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Waiting for (more) payment: the buyer can still pay or send proof. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::STATUS_AWAITING_PAYMENT, self::STATUS_REJECTED], true);
    }
}

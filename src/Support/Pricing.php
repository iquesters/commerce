<?php

namespace Iquesters\Commerce\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Models\ProductDiscount;
use Iquesters\Commerce\Models\ProductDiscountRedemption;

/**
 * Prices with discounts, for any module that sells something (community's
 * membership plans, event tickets…):
 *
 *   $quote = app(Pricing::class)->quote($product, $buyer, $code);   // what it costs
 *   app(Pricing::class)->redeem($quote, $product, $buyer, $period);  // when it is bought
 *
 * The best valid automatic discount applies; a valid coupon code applies when
 * it gives more. Limits (uses in total and per person) are checked when quoting
 * and again, under a lock, when redeeming.
 */
class Pricing
{
    public function quote(Product $product, ?Authenticatable $buyer = null, ?string $code = null): PriceQuote
    {
        $price = (float) $product->price;
        $userId = $buyer?->getAuthIdentifier();

        $automatic = $this->applicable($product)
            ->whereNull('code')
            ->get()
            ->filter(fn (ProductDiscount $d) => $this->withinLimits($d, $userId))
            ->sortByDesc(fn (ProductDiscount $d) => $d->amountOff($price))
            ->first();

        $refused = null;
        $byCode = null;
        if ($code = ProductDiscount::normaliseCode($code)) {
            $byCode = $this->applicable($product)->where('code', $code)->first();
            $refused = match (true) {
                $byCode === null => 'This code is not valid for '.$product->name.'.',
                ! $this->withinLimits($byCode, $userId) => 'This code has already been used up.',
                default => null,
            };
            if ($refused) {
                $byCode = null;
            }
        }

        $best = collect([$automatic, $byCode])->filter()
            ->sortByDesc(fn (ProductDiscount $d) => $d->amountOff($price))
            ->first();

        return new PriceQuote($price, (string) $product->currency, $best, $best ? $best->amountOff($price) : 0.0, $refused);
    }

    /**
     * Record the discount's use for something bought (e.g. a membership period).
     *
     * @throws \DomainException when the discount ran out meanwhile
     */
    public function redeem(PriceQuote $quote, Product $product, ?Authenticatable $buyer = null, ?Model $subject = null): ?ProductDiscountRedemption
    {
        if (! $quote->discount) {
            return null;
        }

        return DB::transaction(function () use ($quote, $product, $buyer, $subject) {
            // Locked: two buyers can't both take the last use.
            $discount = ProductDiscount::query()->whereKey($quote->discount->id)->lockForUpdate()->firstOrFail();
            if (! $this->withinLimits($discount, $buyer?->getAuthIdentifier())) {
                throw new \DomainException('This discount has just been used up. Please check the price again.');
            }

            return ProductDiscountRedemption::create([
                'product_discount_id' => $discount->id,
                'product_id' => $product->id,
                'user_id' => $buyer?->getAuthIdentifier(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'amount' => $quote->discountAmount,
            ]);
        });
    }

    /** Active, in its dates, and for this product (itself, its type, or all of its organisation's). */
    private function applicable(Product $product): Builder
    {
        $now = now();

        return ProductDiscount::query()
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('organisation_id')->orWhere('organisation_id', $product->organisation_id))
            ->where(fn (Builder $q) => $q->where('product_id', $product->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('product_id')
                    ->where(fn (Builder $q) => $q->whereNull('product_type')->orWhere('product_type', $product->type))))
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now));
    }

    private function withinLimits(ProductDiscount $discount, mixed $userId): bool
    {
        if ($discount->max_uses !== null && $discount->redemptions()->count() >= $discount->max_uses) {
            return false;
        }
        if ($discount->max_uses_per_user !== null && $userId !== null
            && $discount->redemptions()->where('user_id', $userId)->count() >= $discount->max_uses_per_user) {
            return false;
        }

        return true;
    }

    /** @return Collection<int, ProductDiscount> automatic discounts that apply now (for showing "Early bird: 20% off"). */
    public function automaticDiscounts(Product $product): Collection
    {
        return $this->applicable($product)->whereNull('code')->get();
    }
}

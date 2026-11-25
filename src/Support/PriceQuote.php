<?php

namespace Iquesters\Commerce\Support;

use Iquesters\Commerce\Models\ProductDiscount;

/** The price of a product for a buyer: list price, the discount applied, the final price. */
final class PriceQuote
{
    public function __construct(
        public readonly float $listPrice,
        public readonly string $currency,
        public readonly ?ProductDiscount $discount = null,
        public readonly float $discountAmount = 0.0,
        /** Why the code entered was refused (the quote then uses the best automatic discount). */
        public readonly ?string $codeRefusedReason = null,
    ) {}

    public function finalPrice(): float
    {
        return round(max(0, $this->listPrice - $this->discountAmount), 2);
    }

    public function code(): ?string
    {
        return $this->discount?->code;
    }

    public function toArray(): array
    {
        return [
            'list_price' => $this->listPrice,
            'discount' => $this->discount ? [
                'uid' => $this->discount->uid,
                'name' => $this->discount->name,
                'code' => $this->discount->code,
                'description' => $this->discount->describe($this->currency),
            ] : null,
            'discount_amount' => $this->discountAmount,
            'final_price' => $this->finalPrice(),
            'currency' => $this->currency,
            'code_refused' => $this->codeRefusedReason,
        ];
    }
}

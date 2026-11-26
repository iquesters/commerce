# Pricing

Modules that sell something point at a product and ask `Pricing` for the price.

```php
use Iquesters\Commerce\Support\Pricing;

$quote = app(Pricing::class)->quote($product, $user, $request->input('code'));

$quote->finalPrice();          // 800.0
$quote->discount?->name;       // "Alumni"
$quote->codeRefusedReason;     // null, or "This code is not valid for …"
$quote->toArray();             // for JSON / views

// When the purchase is made (e.g. a membership period is created):
app(Pricing::class)->redeem($quote, $product, $user, $period);
```

## Which discount applies

1. **Candidates:** active discounts in their dates, for this product, every product of its type, or all of its organisation's products.
2. **Automatic** (no code): the one that takes off the most.
3. **Coupon code:** used when valid and it takes off more than the automatic one. An unknown or used-up code is refused with a reason, and the automatic discount still applies.
4. **Limits:** `max_uses` and `max_uses_per_user` count redemptions. `redeem()` checks them again under a row lock, so two buyers can't both take the last use (it throws `DomainException` then).
5. The final price never goes below 0.

Save the quote's numbers with the purchase: later changes to the product or discount don't change what was owed.

## Access

`ProductAccess`: super-admins manage every organisation's products and discounts; organisation admins theirs (`Iquesters\Organisation\Support\OrganisationAccess`). The admin routes use the `ensure.product.manager` middleware; the data endpoints also follow the organisation the user works in (header switcher).

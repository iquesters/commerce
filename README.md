# iquesters/commerce

Selling for apps built on the iquesters modules: products, prices, discounts and coupon codes; sales orders and receipts next. (Formerly `iquesters/product`.) Other modules sell through it: community's membership plans, event tickets, merchandise, donations.

## What it does

- **Products** of an organisation: name, type (membership, ticket, merchandise, donation, other), price and currency.
- **Discounts:** percent or fixed, on one product, every product of a type, or all of an organisation's products; with dates and use limits (in total and per person).
  - **Automatic** (e.g. "Early bird") or with a **coupon code** people enter.
- **Pricing:** `Pricing::quote($product, $buyer, $code)` gives the list price, the discount and the final price (or why a code was refused); `Pricing::redeem(...)` records a use.
- **Admin screens:** Products, and Discounts & coupons, on the generic table (both modes) and form schemas. Super-admins see all organisations; organisation admins see theirs.

Sales orders and receipts come next, in this module. Payment gateways (Axis / ICICI) will be a separate `payment` module.

## Install

```bash
composer require iquesters/commerce
php artisan migrate
php artisan commerce:seed
```

Requires `iquesters/foundation`, `iquesters/user-interface` and `iquesters/organisation` (2.x).

## Docs

- [Pricing for developers](docs/developer/pricing.md)
- [Settings](docs/developer/configuration.md)
- [Products and coupons](docs/user/products.md) (admins)
- [CHANGELOG](CHANGELOG.md)
